<?php

declare(strict_types=1);

namespace Emorfiq\Mutex;

use Emorfiq\Mutex\Exception\LockNotAcquiredException;

/**
 * Distribuovaný zámek (mutex) – jediný vstupní bod, na kterém závisí aplikační kód.
 *
 * Každý získaný zámek nese unikátní token vlastníka. Uvolnit / prodloužit ho může
 * pouze ten, kdo ho získal (na rozdíl od Laravel CacheEventMutex).
 * Každý zámek má povinné TTL – po pádu procesu se sám uvolní.
 */
interface MutexInterface
{
    /**
     * Pokusí se získat zámek.
     *
     * @param non-empty-string $key         Logický název zámku (např. "scheduler:run:invoice-export").
     * @param positive-int     $ttlSeconds  Doba platnosti zámku; po uplynutí ho může získat někdo jiný.
     * @param int<0, max>      $waitSeconds Jak dlouho čekat (polling), než se vzdá; 0 = jen jeden pokus.
     *
     * @return LockInterface|null Získaný zámek, nebo null, pokud je držen někým jiným.
     */
    public function acquire(string $key, int $ttlSeconds, int $waitSeconds = 0): ?LockInterface;

    /**
     * Provede $callback pod zámkem a zámek vždy uvolní (i při výjimce).
     *
     * @template T
     *
     * @param non-empty-string                 $key
     * @param callable(LockInterface): T       $callback    Dostane zámek, aby mohl volat refresh() u dlouhých operací.
     * @param positive-int                     $ttlSeconds
     * @param int<0, max>                      $waitSeconds
     *
     * @return T
     *
     * @throws LockNotAcquiredException Pokud se zámek nepodařilo získat.
     */
    public function synchronized(string $key, callable $callback, int $ttlSeconds, int $waitSeconds = 0): mixed;

    /**
     * @param non-empty-string $key
     */
    public function isLocked(string $key): bool;

    /**
     * Násilné uvolnění bez ohledu na vlastníka – pouze pro provozní zásahy (CLI / admin).
     *
     * @param non-empty-string $key
     */
    public function forceRelease(string $key): void;
}
