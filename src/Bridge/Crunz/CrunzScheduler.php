<?php

declare(strict_types=1);

namespace Emorfiq\Bridge\Crunz;

use Crunz\Schedule;
use Emorfiq\Scheduler\SchedulerInterface;
use Emorfiq\Scheduler\TaskDefinition;
use Psr\Clock\ClockInterface;

/**
 * Adaptér na crunz (crunzphp/crunz) – připravený pro budoucí přechod, zatím neověřený
 * proti nainstalovanému balíčku.
 *
 * Crunz načítá soubory `tasks/*Tasks.php`, které vrací instanci Crunz\Schedule:
 *
 *   // tasks/EmorfiqTasks.php
 *   $app = require __DIR__ . '/../bootstrap/scheduler.php';     // sestaví DI kontejner
 *   $schedule = new \Crunz\Schedule();
 *   $scheduler = new CrunzScheduler($schedule, [PHP_BINARY, __DIR__ . '/../bin/console'], $clock, $env);
 *   $scheduler->register(...$app->get(TaskRegistryInterface::class)->all());
 *   return $schedule;
 *
 * Crunz preventOverlapping() se NEpoužívá – zámky řeší náš TaskRunner.
 */
final class CrunzScheduler implements SchedulerInterface
{
    /**
     * @param non-empty-list<string> $console   Příkaz konzole, která umí `scheduler:run-task`.
     * @param non-empty-list<string> $crunzBin  Např. [PHP_BINARY, '/app/vendor/bin/crunz'].
     */
    public function __construct(
        private readonly Schedule $schedule,
        private readonly array $console,
        private readonly ClockInterface $clock,
        private readonly string $environment,
        private readonly array $crunzBin = ['vendor/bin/crunz'],
    ) {
    }

    public function register(TaskDefinition ...$tasks): void
    {
        $now = $this->clock->now();
        $tickAt = $now->setTime((int) $now->format('G'), (int) $now->format('i'));
        [$binary, $script] = [$this->console[0], array_slice($this->console, 1)];

        foreach ($tasks as $task) {
            if (!$task->enabled || !$task->runsIn($this->environment)) {
                continue;
            }

            $event = $this->schedule
                ->run($binary, [...$script, 'scheduler:run-task', $task->id, '--scheduled-at=' . $tickAt->format(DATE_ATOM)])
                ->cron($task->cron->expression)
                ->description($task->description ?? $task->id);

            if ($task->timezone !== null) {
                $event->timezone($task->timezone->getName());
            }
            // Crunz spouští všechny splatné události paralelně → runInBackground je implicitní.
        }
    }

    public function tick(): int
    {
        $process = proc_open([...$this->crunzBin, 'schedule:run'], [1 => STDOUT, 2 => STDERR], $pipes);

        return $process === false ? 1 : proc_close($process);
    }
}
