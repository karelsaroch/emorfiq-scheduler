<?php

declare(strict_types=1);

namespace Emorfiq\Scheduler\Exception;

final class InvalidCronExpressionException extends \InvalidArgumentException
{
    public static function because(string $expression, string $reason): self
    {
        return new self(sprintf('Invalid cron expression "%s": %s.', $expression, $reason));
    }
}
