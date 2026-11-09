<?php

declare(strict_types=1);

namespace Emorfiq\Mutex\Store;

use Emorfiq\Mutex\LockStoreInterface;

/**
 * Zámky v Redisu (ext-redis). SET NX PX + Lua compare-and-delete / compare-and-expire,
 * takže cizí zámek nelze nikdy smazat ani prodloužit.
 *
 * Pro jednu Redis instanci (resp. master). Redlock přes více nezávislých instancí
 * lze doplnit jako další LockStoreInterface bez dopadu na zbytek systému.
 */
final class RedisLockStore implements LockStoreInterface
{
    private const RELEASE_SCRIPT = <<<'LUA'
        if redis.call('get', KEYS[1]) == ARGV[1] then
            return redis.call('del', KEYS[1])
        end
        return 0
        LUA;

    private const REFRESH_SCRIPT = <<<'LUA'
        if redis.call('get', KEYS[1]) == ARGV[1] then
            return redis.call('pexpire', KEYS[1], ARGV[2])
        end
        return 0
        LUA;

    public function __construct(private readonly \Redis $redis)
    {
    }

    public function tryAcquire(string $key, string $token, int $ttlMs): bool
    {
        return $this->redis->set($key, $token, ['nx', 'px' => $ttlMs]) === true;
    }

    public function release(string $key, string $token): bool
    {
        return (int) $this->redis->eval(self::RELEASE_SCRIPT, [$key, $token], 1) === 1;
    }

    public function refresh(string $key, string $token, int $ttlMs): bool
    {
        return (int) $this->redis->eval(self::REFRESH_SCRIPT, [$key, $token, $ttlMs], 1) === 1;
    }

    public function owner(string $key): ?string
    {
        $value = $this->redis->get($key);

        return is_string($value) ? $value : null;
    }

    public function forceRelease(string $key): void
    {
        $this->redis->del($key);
    }
}
