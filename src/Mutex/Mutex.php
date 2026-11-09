<?php

declare(strict_types=1);

namespace Emorfiq\Mutex;

use Emorfiq\Mutex\Exception\LockNotAcquiredException;

/**
 * Výchozí implementace {@see MutexInterface} nad libovolným {@see LockStoreInterface}.
 */
final class Mutex implements MutexInterface
{
    private const MAX_KEY_LENGTH = 191;

    /**
     * @param string       $prefix          Namespace klíčů (např. název aplikace / prostředí).
     * @param positive-int $retryIntervalMs Interval pollingu při $waitSeconds > 0.
     */
    public function __construct(
        private readonly LockStoreInterface $store,
        private readonly string $prefix = '',
        private readonly int $retryIntervalMs = 100,
    ) {
    }

    public function acquire(string $key, int $ttlSeconds, int $waitSeconds = 0): ?LockInterface
    {
        if ($ttlSeconds < 1) {
            throw new \InvalidArgumentException('TTL must be at least 1 second.');
        }
        if ($waitSeconds < 0) {
            throw new \InvalidArgumentException('Wait time must not be negative.');
        }

        $storeKey = $this->storeKey($key);
        $token = bin2hex(random_bytes(16));
        $deadline = hrtime(true) + $waitSeconds * 1_000_000_000;

        do {
            if ($this->store->tryAcquire($storeKey, $token, $ttlSeconds * 1000)) {
                return new Lock($this->store, $storeKey, $token, $ttlSeconds);
            }
            if ($waitSeconds === 0 || hrtime(true) >= $deadline) {
                return null;
            }
            usleep($this->retryIntervalMs * 1000);
        } while (true);
    }

    public function synchronized(string $key, callable $callback, int $ttlSeconds, int $waitSeconds = 0): mixed
    {
        $lock = $this->acquire($key, $ttlSeconds, $waitSeconds)
            ?? throw LockNotAcquiredException::forKey($key, $waitSeconds);

        try {
            return $callback($lock);
        } finally {
            $lock->release();
        }
    }

    public function isLocked(string $key): bool
    {
        return $this->store->owner($this->storeKey($key)) !== null;
    }

    public function forceRelease(string $key): void
    {
        $this->store->forceRelease($this->storeKey($key));
    }

    /** @return non-empty-string */
    private function storeKey(string $key): string
    {
        if ($key === '') {
            throw new \InvalidArgumentException('Lock key must not be empty.');
        }
        $full = $this->prefix . $key;

        // Dlouhé klíče zkrátíme deterministicky na MAX_KEY_LENGTH (limit DB sloupce):
        // čitelný začátek + ':' + sha256 (64 znaků) celého klíče.
        return strlen($full) > self::MAX_KEY_LENGTH
            ? substr($full, 0, self::MAX_KEY_LENGTH - 65) . ':' . hash('sha256', $full)
            : $full;
    }
}
