<?php

declare(strict_types=1);

namespace Emorfiq\Mutex;

/**
 * Handle na získaný zámek. Všechny operace ověřují vlastnictví (token).
 */
interface LockInterface
{
    /** @return non-empty-string */
    public function key(): string;

    /** Unikátní token vlastníka (náhodný, 128 bit). @return non-empty-string */
    public function token(): string;

    /** Lokálně odhadnutý okamžik expirace (podle posledního acquire/refresh). */
    public function expiresAt(): \DateTimeImmutable;

    /**
     * Prodlouží platnost zámku (heartbeat pro dlouhé úlohy).
     *
     * @param positive-int|null $ttlSeconds null = stejné TTL jako při získání.
     *
     * @return bool false = zámek už nevlastníme (vypršel a převzal ho někdo jiný) – volající by měl práci ukončit.
     */
    public function refresh(?int $ttlSeconds = null): bool;

    /**
     * Uvolní zámek, pokud ho stále vlastníme. Idempotentní.
     *
     * @return bool true = zámek byl uvolněn tímto voláním.
     */
    public function release(): bool;

    /** Ověří proti úložišti, že zámek stále drží tento vlastník. */
    public function isHeld(): bool;
}
