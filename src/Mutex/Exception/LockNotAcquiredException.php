<?php

declare(strict_types=1);

namespace Emorfiq\Mutex\Exception;

final class LockNotAcquiredException extends \RuntimeException
{
    public static function forKey(string $key, int $waitSeconds): self
    {
        return new self(sprintf('Lock "%s" could not be acquired within %d s.', $key, $waitSeconds));
    }
}
