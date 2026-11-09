<?php

declare(strict_types=1);

namespace Emorfiq\Bridge\Laravel;

use Emorfiq\Scheduler\Exception\TaskNotFoundException;
use Emorfiq\Scheduler\TaskRunnerInterface;
use Illuminate\Console\Command;

/**
 * Spouští jednu úlohu. Volá ho engine (Laravel / crunz / native), lze ho pustit i ručně.
 */
final class RunTaskCommand extends Command
{
    public const NAME = 'scheduler:run-task';

    protected $signature = self::NAME . '
        {task : ID úlohy z registru}
        {--scheduled-at= : Minuta naplánování (ISO 8601); výchozí = teď}';

    protected $description = 'Run a single scheduled task (with mutex handling).';

    public function handle(TaskRunnerInterface $runner): int
    {
        $scheduledAt = $this->option('scheduled-at');

        try {
            $result = $runner->run(
                (string) $this->argument('task'),
                is_string($scheduledAt) && $scheduledAt !== '' ? new \DateTimeImmutable($scheduledAt) : null,
            );
        } catch (TaskNotFoundException $e) {
            $this->error($e->getMessage());

            return self::INVALID;
        }

        $this->line(sprintf('[%s] %s (%s ms)', $result->status->value, $result->taskId, $result->durationMs() ?? '-'));
        if ($result->error !== null) {
            $this->error($result->error->getMessage());
        }

        return $result->exitCode();
    }
}
