<?php

declare(strict_types=1);

namespace Emorfiq\Tests\Support;

use Emorfiq\Scheduler\TaskContext;
use Emorfiq\Scheduler\TaskHandlerInterface;

final class FailingHandler implements TaskHandlerInterface
{
    public function handle(TaskContext $context): void
    {
        throw new \RuntimeException('Task failed on purpose');
    }
}
