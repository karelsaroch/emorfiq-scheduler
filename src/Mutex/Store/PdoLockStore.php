<?php

declare(strict_types=1);

namespace Emorfiq\Mutex\Store;

use Emorfiq\Mutex\LockStoreInterface;
use Psr\Clock\ClockInterface;

/**
 * Zámky v relační DB (MySQL/MariaDB, PostgreSQL, SQLite).
 *
 * Atomicitu zajišťuje PRIMARY KEY nad lock_key: expirovaný záznam se smaže
 * (pouze pokud je expirovaný) a pak se zkusí INSERT – souběžný INSERT skončí
 * porušením unikátnosti (SQLSTATE 23xxx).
 *
 * DŮLEŽITÉ: používejte vyhrazené PDO spojení v autocommit režimu. Uvnitř
 * aplikační transakce by byl zámek neviditelný pro ostatní a na PostgreSQL
 * by chyba INSERTu zneplatnila celou transakci.
 *
 * Schéma: tabulku zakládá migrace – database/sql/scheduler_locks.sql (libovolný nástroj)
 * nebo Laravel migrace v database/migrations. Store sám DDL nespouští.
 *
 * Čas: expirace se počítá z hodin PHP serveru → servery musí mít synchronizovaný čas (NTP).
 *
 * Souběh: InnoDB při souběžném INSERT (duplicitní klíč) + DELETE téhož řádku hlásí deadlock
 * (1213 / SQLSTATE 40001) i v autocommitu. Obětovaný příkaz je vrácen, proto:
 * tryAcquire() → "nezískáno" (Mutex zkusí znovu), release()/refresh() → krátký retry.
 */
final class PdoLockStore implements LockStoreInterface
{
    public function __construct(
        private readonly \PDO $pdo,
        private readonly string $table = 'scheduler_locks',
        private readonly ?ClockInterface $clock = null,
    ) {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table)) {
            throw new \InvalidArgumentException(sprintf('Invalid table name "%s".', $table));
        }
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }

    public function tryAcquire(string $key, string $token, int $ttlMs): bool
    {
        $now = $this->nowMs();

        try {
            $this->pdo
                ->prepare("DELETE FROM {$this->table} WHERE lock_key = ? AND expires_at <= ?")
                ->execute([$key, $now]);

            $this->pdo
                ->prepare("INSERT INTO {$this->table} (lock_key, owner, expires_at) VALUES (?, ?, ?)")
                ->execute([$key, $token, $now + $ttlMs]);

            return true;
        } catch (\PDOException $e) {
            if (self::sqlState($e) === '23' || self::isTransientConflict($e)) {
                return false; // zámek drží někdo jiný / souběh – příkaz byl vrácen, nic jsme nezískali
            }
            throw $e;
        }
    }

    public function release(string $key, string $token): bool
    {
        return $this->retryOnConflict(function () use ($key, $token): bool {
            $stmt = $this->pdo->prepare("DELETE FROM {$this->table} WHERE lock_key = ? AND owner = ?");
            $stmt->execute([$key, $token]);

            return $stmt->rowCount() > 0;
        });
    }

    public function refresh(string $key, string $token, int $ttlMs): bool
    {
        return $this->retryOnConflict(function () use ($key, $token, $ttlMs): bool {
            $now = $this->nowMs();
            $stmt = $this->pdo->prepare(
                "UPDATE {$this->table} SET expires_at = ? WHERE lock_key = ? AND owner = ? AND expires_at > ?",
            );
            $stmt->execute([$now + $ttlMs, $key, $token, $now]);

            return $stmt->rowCount() > 0;
        });
    }

    public function owner(string $key): ?string
    {
        $stmt = $this->pdo->prepare("SELECT owner FROM {$this->table} WHERE lock_key = ? AND expires_at > ?");
        $stmt->execute([$key, $this->nowMs()]);
        $owner = $stmt->fetchColumn();

        return $owner === false ? null : (string) $owner;
    }

    public function forceRelease(string $key): void
    {
        $this->pdo->prepare("DELETE FROM {$this->table} WHERE lock_key = ?")->execute([$key]);
    }

    /** Úklid expirovaných záznamů (např. denní úloha). */
    public function purgeExpired(): int
    {
        $stmt = $this->pdo->prepare("DELETE FROM {$this->table} WHERE expires_at <= ?");
        $stmt->execute([$this->nowMs()]);

        return $stmt->rowCount();
    }

    /**
     * @param callable(): bool $operation
     */
    private function retryOnConflict(callable $operation, int $attempts = 10): bool
    {
        for ($attempt = 1; ; ++$attempt) {
            try {
                return $operation();
            } catch (\PDOException $e) {
                if ($attempt >= $attempts || !self::isTransientConflict($e)) {
                    throw $e;
                }
                usleep(random_int(1_000, 10_000) * $attempt);
            }
        }
    }

    /** Deadlock / serialization failure / lock wait timeout (MySQL, PostgreSQL, SQLite busy). */
    private static function isTransientConflict(\PDOException $e): bool
    {
        $state = (string) ($e->errorInfo[0] ?? $e->getCode());
        $driverCode = (int) ($e->errorInfo[1] ?? 0);

        return in_array($state, ['40001', '40P01'], true)
            || in_array($driverCode, [1205, 1213], true)   // MySQL lock wait timeout, deadlock
            || in_array($driverCode, [5, 6], true) && $state === 'HY000'; // SQLITE_BUSY, SQLITE_LOCKED
    }

    /** První dva znaky SQLSTATE (třída chyby). */
    private static function sqlState(\PDOException $e): string
    {
        return substr((string) ($e->errorInfo[0] ?? $e->getCode()), 0, 2);
    }

    private function nowMs(): int
    {
        $now = $this->clock?->now() ?? new \DateTimeImmutable();

        return (int) $now->format('Uv');
    }
}
