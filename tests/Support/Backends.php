<?php

declare(strict_types=1);

namespace Emorfiq\Tests\Support;

use Emorfiq\Mutex\LockStoreInterface;
use Emorfiq\Mutex\Store\FlockLockStore;
use Emorfiq\Mutex\Store\PdoLockStore;
use Emorfiq\Mutex\Store\RedisLockStore;
use Psr\Clock\ClockInterface;

/**
 * Továrna na úložiště zámků podle prostředí (env proměnné dodává docker-compose).
 * Používají ji contract testy i pomocný skript tests/bin/contend.php (souběžné procesy).
 */
final class Backends
{
    public const TABLE = 'test_scheduler_locks';

    /** Proč backend není k dispozici, nebo null. */
    public static function unavailable(string $backend): ?string
    {
        return match ($backend) {
            'mysql' => getenv('MYSQL_DSN') ? null : 'MYSQL_DSN not set (run via docker compose)',
            'pgsql' => getenv('PGSQL_DSN') ? null : 'PGSQL_DSN not set (run via docker compose)',
            'redis' => !extension_loaded('redis') ? 'ext-redis missing' : (getenv('REDIS_HOST') ? null : 'REDIS_HOST not set (run via docker compose)'),
            'sqlite-file', 'flock' => null,
            default => throw new \InvalidArgumentException("Unknown backend $backend"),
        };
    }

    public static function create(string $backend, ?ClockInterface $clock = null): LockStoreInterface
    {
        return match ($backend) {
            'mysql' => self::pdoStore(self::pdo('MYSQL'), $clock),
            'pgsql' => self::pdoStore(self::pdo('PGSQL'), $clock),
            'sqlite-file' => self::pdoStore(self::sqliteFile(), $clock),
            'redis' => new RedisLockStore(self::redis()),
            'flock' => new FlockLockStore(sys_get_temp_dir() . '/emorfiq-test-locks'),
            default => throw new \InvalidArgumentException("Unknown backend $backend"),
        };
    }

    public static function redis(): \Redis
    {
        $redis = new \Redis();
        $redis->connect((string) getenv('REDIS_HOST'), (int) (getenv('REDIS_PORT') ?: 6379), 2.0);

        return $redis;
    }

    public static function pdo(string $prefix): \PDO
    {
        return new \PDO((string) getenv("{$prefix}_DSN"), (string) getenv("{$prefix}_USER"), (string) getenv("{$prefix}_PASSWORD"));
    }

    private static function sqliteFile(): \PDO
    {
        $pdo = new \PDO('sqlite:' . sys_get_temp_dir() . '/emorfiq-test-locks.sqlite');
        $pdo->exec('PRAGMA busy_timeout = 5000');

        return $pdo;
    }

    private static function pdoStore(\PDO $pdo, ?ClockInterface $clock): PdoLockStore
    {
        LockSchema::install($pdo, self::TABLE);

        return new PdoLockStore($pdo, self::TABLE, $clock);
    }
}
