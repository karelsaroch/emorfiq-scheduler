<?php

declare(strict_types=1);

namespace Emorfiq\Mutex;

/**
 * Nízkoúrovňové úložiště zámků (SPI). Implementace musí zajistit atomicitu
 * každé operace a kontrolu vlastníka podle tokenu.
 *
 * Mutex logiku (čekání, tokeny, prefixy) řeší {@see Mutex}; úložiště je proto
 * triviálně vyměnitelné (PDO, Redis, flock, in-memory, …).
 */
interface LockStoreInterface
{
    /**
     * Atomicky vytvoří zámek, pokud neexistuje nebo je expirovaný.
     *
     * @param non-empty-string $key
     * @param non-empty-string $token
     * @param positive-int     $ttlMs
     */
    public function tryAcquire(string $key, string $token, int $ttlMs): bool;

    /**
     * Smaže zámek, pouze pokud patří $token.
     */
    public function release(string $key, string $token): bool;

    /**
     * Nastaví novou expiraci (now + $ttlMs), pouze pokud zámek patří $token a ještě nevypršel.
     *
     * @param positive-int $ttlMs
     */
    public function refresh(string $key, string $token, int $ttlMs): bool;

    /**
     * Token aktuálního (neexpirovaného) vlastníka, nebo null.
     */
    public function owner(string $key): ?string;

    public function forceRelease(string $key): void;
}
