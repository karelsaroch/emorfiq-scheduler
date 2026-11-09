<?php

declare(strict_types=1);

namespace Emorfiq\Mutex;

/**
 * @internal Vytváří výhradně {@see Mutex}.
 */
final class Lock implements LockInterface
{
    private bool $released = false;
    private \DateTimeImmutable $expiresAt;

    /**
     * @param non-empty-string $key
     * @param non-empty-string $token
     * @param positive-int     $ttlSeconds
     */
    public function __construct(
        private readonly LockStoreInterface $store,
        private readonly string $key,
        private readonly string $token,
        private readonly int $ttlSeconds,
    ) {
        $this->expiresAt = self::expiry($ttlSeconds);
    }

    public function key(): string
    {
        return $this->key;
    }

    public function token(): string
    {
        return $this->token;
    }

    public function expiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function refresh(?int $ttlSeconds = null): bool
    {
        $ttl = $ttlSeconds ?? $this->ttlSeconds;
        if ($ttl < 1) {
            throw new \InvalidArgumentException('TTL must be at least 1 second.');
        }
        if ($this->released || !$this->store->refresh($this->key, $this->token, $ttl * 1000)) {
            return false;
        }
        $this->expiresAt = self::expiry($ttl);

        return true;
    }

    public function release(): bool
    {
        if ($this->released) {
            return false;
        }
        $this->released = true;

        return $this->store->release($this->key, $this->token);
    }

    public function isHeld(): bool
    {
        return !$this->released && $this->store->owner($this->key) === $this->token;
    }

    private static function expiry(int $ttlSeconds): \DateTimeImmutable
    {
        return new \DateTimeImmutable(sprintf('+%d seconds', $ttlSeconds));
    }
}
