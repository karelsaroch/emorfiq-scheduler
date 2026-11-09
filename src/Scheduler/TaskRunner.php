<?php

declare(strict_types=1);

namespace Emorfiq\Scheduler;

use Emorfiq\Mutex\LockInterface;
use Emorfiq\Mutex\MutexInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Engine-agnostické provedení úlohy:
 *
 * 1. onOneServer      → zámek výskytu  `scheduler:occurrence:<id>:<YmdHi>` (neuvolňuje se, vyprší)
 * 2. withoutOverlapping → běhový zámek `scheduler:run:<id>[:<host>]` (uvolní se ve finally)
 * 3. resolve handleru z DI, handle(), zachycení výjimky, log
 */
final class TaskRunner implements TaskRunnerInterface
{
    /** Jak dlouho drží zámek výskytu – musí pokrýt rozptyl hodin a zpoždění startu mezi servery. */
    private const OCCURRENCE_LOCK_TTL = 3600;

    public function __construct(
        private readonly TaskRegistryInterface $registry,
        private readonly HandlerResolverInterface $resolver,
        private readonly MutexInterface $mutex,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly string $hostname = '',
    ) {
    }

    public function run(string $taskId, ?\DateTimeImmutable $scheduledAt = null): TaskRunResult
    {
        $task = $this->registry->get($taskId);
        $scheduledAt = self::truncateToMinute($scheduledAt ?? $this->clock->now());
        $runId = bin2hex(random_bytes(8));
        $log = ['task' => $task->id, 'run_id' => $runId, 'scheduled_at' => $scheduledAt->format(DATE_ATOM)];

        if (!$task->enabled) {
            return $this->skipped($task, $runId, $scheduledAt, TaskRunStatus::SkippedDisabled, $log);
        }

        $policy = $task->policy;

        if ($policy->onOneServer) {
            $occurrence = $this->mutex->acquire(
                sprintf('scheduler:occurrence:%s:%s', $task->id, $scheduledAt->format('YmdHi')),
                self::OCCURRENCE_LOCK_TTL,
            );
            if ($occurrence === null) {
                return $this->skipped($task, $runId, $scheduledAt, TaskRunStatus::SkippedAlreadyHandled, $log);
            }
        }

        $runLock = null;
        if ($policy->withoutOverlapping) {
            $runLock = $this->mutex->acquire($this->runLockKey($task), $policy->lockTtlSeconds, $policy->lockWaitSeconds);
            if ($runLock === null) {
                return $this->skipped($task, $runId, $scheduledAt, TaskRunStatus::SkippedOverlapping, $log);
            }
        }

        try {
            return $this->execute($task, $runId, $scheduledAt, $runLock, $log);
        } finally {
            if ($runLock !== null && !$runLock->release()) {
                $this->logger->warning('Scheduled task lock was lost before release (TTL too short?).', $log);
            }
        }
    }

    /** @param array<string, string> $log */
    private function execute(TaskDefinition $task, string $runId, \DateTimeImmutable $scheduledAt, ?LockInterface $lock, array $log): TaskRunResult
    {
        $startedAt = $this->clock->now();
        $this->logger->info('Scheduled task started.', $log);

        try {
            $this->resolver->resolve($task->handler)->handle(new TaskContext($task, $scheduledAt, $runId, $lock));
            $status = TaskRunStatus::Succeeded;
            $error = null;
        } catch (\Throwable $e) {
            $status = TaskRunStatus::Failed;
            $error = $e;
        }

        $result = new TaskRunResult($task->id, $runId, $status, $scheduledAt, $startedAt, $this->clock->now(), $error);

        if ($error !== null) {
            $this->logger->error('Scheduled task failed.', $log + ['exception' => $error, 'duration_ms' => $result->durationMs()]);
        } else {
            $this->logger->info('Scheduled task finished.', $log + ['duration_ms' => $result->durationMs()]);
        }

        return $result;
    }

    /** @param array<string, string> $log */
    private function skipped(TaskDefinition $task, string $runId, \DateTimeImmutable $scheduledAt, TaskRunStatus $status, array $log): TaskRunResult
    {
        $this->logger->info('Scheduled task skipped.', $log + ['reason' => $status->value]);

        return new TaskRunResult($task->id, $runId, $status, $scheduledAt);
    }

    private function runLockKey(TaskDefinition $task): string
    {
        // onOneServer → zákaz souběhu v celém clusteru; jinak jen na tomto serveru.
        return $task->policy->onOneServer || $this->hostname === ''
            ? sprintf('scheduler:run:%s', $task->id)
            : sprintf('scheduler:run:%s:%s', $task->id, $this->hostname);
    }

    private static function truncateToMinute(\DateTimeImmutable $at): \DateTimeImmutable
    {
        return $at->setTime((int) $at->format('G'), (int) $at->format('i'));
    }
}
