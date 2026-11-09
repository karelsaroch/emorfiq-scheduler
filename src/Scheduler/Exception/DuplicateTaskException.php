<?php

declare(strict_types=1);

namespace Emorfiq\Scheduler\Exception;

final class DuplicateTaskException extends \LogicException
{
    public static function withId(string $id): self
    {
        return new self(sprintf('Scheduled task "%s" is already registered.', $id));
    }
}
