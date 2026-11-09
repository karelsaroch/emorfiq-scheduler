<?php

declare(strict_types=1);

namespace Emorfiq\Scheduler;

final readonly class TaskRunResult
{
    public function __construct(
        public string $taskId,
        public string $runId,
        public TaskRunStatus $status,
        public \DateTimeImmutable $scheduledAt,
        public ?\DateTimeImmutable $startedAt = null,
        public ?\DateTimeImmutable $finishedAt = null,
        public ?\Throwable $error = null,
    ) {
    }

    public function durationMs(): ?int
    {
        if ($this->startedAt === null || $this->finishedAt === null) {
            return null;
        }

        return (int) $this->finishedAt->format('Uv') - (int) $this->startedAt->format('Uv');
    }

    /** Přeskočení není chyba → 0; selhání → 1. */
    public function exitCode(): int
    {
        return $this->status === TaskRunStatus::Failed ? 1 : 0;
    }
}
