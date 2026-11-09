<?php

declare(strict_types=1);

namespace Emorfiq\E2E\Handler;

use Emorfiq\Scheduler\TaskContext;
use Emorfiq\Scheduler\TaskHandlerInterface;

/**
 * Simuluje práci; každou sekundu volá heartbeat (prodloužení zámku).
 */
final class SleepHandler implements TaskHandlerInterface
{
    public function handle(TaskContext $context): void
    {
        $seconds = (int) $context->parameter('seconds', 1);

        for ($i = 0; $i < $seconds; ++$i) {
            sleep(1);
            if (!$context->heartbeat()) {
                throw new \RuntimeException('Lock lost during execution');
            }
        }
    }
}
