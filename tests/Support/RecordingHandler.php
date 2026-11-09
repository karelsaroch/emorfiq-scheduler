<?php

declare(strict_types=1);

namespace Emorfiq\Tests\Support;

use Emorfiq\Scheduler\TaskContext;
use Emorfiq\Scheduler\TaskHandlerInterface;

final class RecordingHandler implements TaskHandlerInterface
{
    /** @var list<TaskContext> */
    public static array $contexts = [];

    /** @var (\Closure(TaskContext): void)|null */
    public static ?\Closure $during = null;

    public function handle(TaskContext $context): void
    {
        self::$contexts[] = $context;
        if (self::$during !== null) {
            (self::$during)($context);
        }
    }

    public static function reset(): void
    {
        self::$contexts = [];
        self::$during = null;
    }
}
