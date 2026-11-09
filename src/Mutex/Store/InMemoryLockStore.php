<?php

declare(strict_types=1);

namespace Emorfiq\Mutex\Store;

use Emorfiq\Mutex\LockStoreInterface;
use Psr\Clock\ClockInterface;

/**
 * Pro testy a jednoprocesové použití.
 */
final class InMemoryLockStore implements LockStoreInterface
{
    /** @var array<string, array{token: string, expiresAt: int}> */
    private array $locks = [];

    public function __construct(private readonly ?ClockInterface $clock = null)
    {
    }

    public function tryAcquire(string $key, string $token, int $ttlMs): bool
    {
        if ($this->owner($key) !== null) {
            return false;
        }
        $this->locks[$key] = ['token' => $token, 'expiresAt' => $this->nowMs() + $ttlMs];

        return true;
    }

    public function release(string $key, string $token): bool
    {
        if (($this->locks[$key]['token'] ?? null) !== $token) {
            return false;
        }
        unset($this->locks[$key]);

        return true;
    }

    public function refresh(string $key, string $token, int $ttlMs): bool
    {
        if ($this->owner($key) !== $token) {
            return false;
        }
        $this->locks[$key]['expiresAt'] = $this->nowMs() + $ttlMs;

        return true;
    }

    public function owner(string $key): ?string
    {
        $lock = $this->locks[$key] ?? null;

        return $lock !== null && $lock['expiresAt'] > $this->nowMs() ? $lock['token'] : null;
    }

    public function forceRelease(string $key): void
    {
        unset($this->locks[$key]);
    }

    private function nowMs(): int
    {
        return (int) ($this->clock?->now() ?? new \DateTimeImmutable())->format('Uv');
    }
}
