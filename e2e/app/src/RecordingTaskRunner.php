<?php

declare(strict_types=1);

namespace Emorfiq\E2E;

use Emorfiq\Scheduler\TaskRunnerInterface;
use Emorfiq\Scheduler\TaskRunResult;
use Illuminate\Database\ConnectionInterface;

/**
 * Dekorátor TaskRunneru – ukázka rozšíření bez zásahu do jádra.
 */
final class RecordingTaskRunner implements TaskRunnerInterface
{
    public function __construct(
        private readonly TaskRunnerInterface $inner,
        private readonly ConnectionInterface $db,
    ) {
    }

    public function run(string $taskId, ?\DateTimeImmutable $scheduledAt = null): TaskRunResult
    {
        $result = $this->inner->run($taskId, $scheduledAt);

        $this->db->table('e2e_results')->insert([
            'task_id' => $result->taskId,
            'host' => (string) gethostname(),
            'run_id' => $result->runId,
            'status' => $result->status->value,
            'scheduled_at' => $result->scheduledAt->format('Y-m-d H:i:s'),
            'started_at_ms' => $result->startedAt?->format('Uv'),
            'finished_at_ms' => $result->finishedAt?->format('Uv'),
            'error' => $result->error?->getMessage(),
        ]);

        return $result;
    }
}
