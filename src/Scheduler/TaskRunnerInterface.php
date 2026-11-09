<?php

declare(strict_types=1);

namespace Emorfiq\Scheduler;

use Emorfiq\Scheduler\Exception\TaskNotFoundException;

/**
 * Provede jeden běh úlohy včetně zámků podle {@see ExecutionPolicy}.
 * Nezávislé na enginu – volá ho CLI příkaz `scheduler:run-task`.
 */
interface TaskRunnerInterface
{
    /**
     * @param non-empty-string        $taskId
     * @param \DateTimeImmutable|null $scheduledAt Minuta naplánování od enginu; null = aktuální minuta.
     *
     * @throws TaskNotFoundException
     */
    public function run(string $taskId, ?\DateTimeImmutable $scheduledAt = null): TaskRunResult;
}
