<?php

declare(strict_types=1);

namespace Emorfiq\Mutex\Store;

use Emorfiq\Mutex\LockStoreInterface;

/**
 * Lokální zámky přes flock() – POUZE pro jeden server (sdílený NFS nepoužívat).
 *
 * Výhoda: zámek uvolní jádro OS při ukončení procesu (i kill -9), TTL se proto
 * ignoruje. Zámek je vázán na proces, který ho získal.
 */
final class FlockLockStore implements LockStoreInterface
{
    /** @var array<string, array{handle: resource, token: string}> */
    private array $held = [];

    public function __construct(private readonly string $directory)
    {
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Cannot create lock directory "%s".', $directory));
        }
    }

    public function tryAcquire(string $key, string $token, int $ttlMs): bool
    {
        if (isset($this->held[$key])) {
            return false;
        }
        $handle = fopen($this->path($key), 'c+');
        if ($handle === false) {
            throw new \RuntimeException(sprintf('Cannot open lock file for "%s".', $key));
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return false;
        }
        ftruncate($handle, 0);
        fwrite($handle, $token);
        fflush($handle);
        $this->held[$key] = ['handle' => $handle, 'token' => $token];

        return true;
    }

    public function release(string $key, string $token): bool
    {
        if (($this->held[$key]['token'] ?? null) !== $token) {
            return false;
        }
        $handle = $this->held[$key]['handle'];
        unset($this->held[$key]);
        ftruncate($handle, 0);
        flock($handle, LOCK_UN);
        fclose($handle);

        return true;
    }

    public function refresh(string $key, string $token, int $ttlMs): bool
    {
        return ($this->held[$key]['token'] ?? null) === $token;
    }

    public function owner(string $key): ?string
    {
        if (isset($this->held[$key])) {
            return $this->held[$key]['token'];
        }
        $path = $this->path($key);
        if (!is_file($path) || ($handle = fopen($path, 'r')) === false) {
            return null;
        }
        try {
            if (flock($handle, LOCK_SH | LOCK_NB)) {
                flock($handle, LOCK_UN);

                return null; // nikdo nedrží
            }
            $token = stream_get_contents($handle);

            return $token === false || $token === '' ? '?' : $token;
        } finally {
            fclose($handle);
        }
    }

    public function forceRelease(string $key): void
    {
        // flock nelze cizímu procesu odebrat; lze uvolnit jen zámek držený tímto procesem.
        if (isset($this->held[$key])) {
            $this->release($key, $this->held[$key]['token']);
        }
    }

    private function path(string $key): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . hash('sha256', $key) . '.lock';
    }
}
