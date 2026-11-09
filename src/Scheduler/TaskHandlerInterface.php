<?php

declare(strict_types=1);

namespace Emorfiq\Scheduler;

/**
 * Implementuje každá plánovaná úloha. Neví nic o enginu ani o frameworku.
 * Selhání = vyhozená výjimka.
 */
interface TaskHandlerInterface
{
    public function handle(TaskContext $context): void;
}
