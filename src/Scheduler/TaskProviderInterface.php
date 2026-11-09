<?php

declare(strict_types=1);

namespace Emorfiq\Scheduler;

/**
 * Moduly aplikace přispívají svými úlohami (v Laravelu otagované služby).
 */
interface TaskProviderInterface
{
    /** @return iterable<TaskDefinition> */
    public function tasks(): iterable;
}
