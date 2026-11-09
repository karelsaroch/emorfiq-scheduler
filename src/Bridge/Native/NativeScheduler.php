<?php

declare(strict_types=1);

namespace Emorfiq\Bridge\Native;

use Emorfiq\Scheduler\SchedulerInterface;
use Emorfiq\Scheduler\TaskDefinition;
use Psr\Clock\ClockInterface;

/**
 * Minimalistický vlastní engine bez knihoven třetích stran – záložní varianta
 * a důkaz, že rozhraní nejsou šitá na Laravel.
 *
 * tick(): vybere splatné úlohy a pro každou spustí samostatný proces
 * `<console> scheduler:run-task <id> --scheduled-at=<ISO>`.
 */
final class NativeScheduler implements SchedulerInterface
{
    /** @var array<string, TaskDefinition> */
    private array $tasks = [];

    /**
     * @param non-empty-list<string> $console Příkaz konzole, např. [PHP_BINARY, '/app/artisan'] nebo [PHP_BINARY, '/app/bin/console'].
     */
    public function __construct(
        private readonly array $console,
        private readonly ClockInterface $clock,
        private readonly string $environment,
    ) {
    }

    public function register(TaskDefinition ...$tasks): void
    {
        foreach ($tasks as $task) {
            if ($task->enabled && $task->runsIn($this->environment)) {
                $this->tasks[$task->id] = $task;
            }
        }
    }

    public function tick(): int
    {
        $now = $this->clock->now();
        $tickAt = $now->setTime((int) $now->format('G'), (int) $now->format('i'));
        $exitCode = 0;

        foreach ($this->tasks as $task) {
            if (!$task->isDue($tickAt)) {
                continue;
            }
            $command = [...$this->console, 'scheduler:run-task', $task->id, '--scheduled-at=' . $tickAt->format(DATE_ATOM)];
            $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => STDOUT, 2 => STDERR], $pipes);
            if ($process === false) {
                $exitCode = 1;
                continue;
            }
            if (!$task->policy->runInBackground) {
                $exitCode = max($exitCode, proc_close($process) === 0 ? 0 : 1);
            }
            // Na background procesy nečekáme; zámky řeší TaskRunner v podprocesu.
        }

        return $exitCode;
    }
}
