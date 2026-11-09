<?php

declare(strict_types=1);

namespace Emorfiq\E2E\Handler;

use Emorfiq\Scheduler\TaskContext;
use Emorfiq\Scheduler\TaskHandlerInterface;

final class FailingHandler implements TaskHandlerInterface
{
    public function handle(TaskContext $context): void
    {
        throw new \RuntimeException('E2E failure ' . $context->scheduledAt->format('H:i'));
    }
}
