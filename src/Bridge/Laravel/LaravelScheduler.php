<?php

declare(strict_types=1);

namespace Emorfiq\Bridge\Laravel;

use Emorfiq\Scheduler\SchedulerInterface;
use Emorfiq\Scheduler\TaskDefinition;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Psr\Clock\ClockInterface;

/**
 * Adaptér na Laravel Scheduler. Laravel slouží POUZE jako časovač:
 *  - NEpoužívá se withoutOverlapping() / onOneServer() (Laravel EventMutex/SchedulingMutex),
 *  - NEpoužívá se $schedule->call()/job() (closure by nešly přenést do jiného enginu),
 *  - každá úloha = `artisan scheduler:run-task <id> --scheduled-at=…` v samostatném procesu.
 */
final class LaravelScheduler implements SchedulerInterface
{
    public function __construct(
        private readonly Schedule $schedule,
        private readonly ConsoleKernel $artisan,
        private readonly ClockInterface $clock,
        private readonly string $environment,
    ) {
    }

    public function register(TaskDefinition ...$tasks): void
    {
        // Schedule se sestavuje při každém `schedule:run` → "teď" je minuta ticku.
        $now = $this->clock->now();
        $tickAt = $now->setTime((int) $now->format('G'), (int) $now->format('i'));

        foreach ($tasks as $task) {
            if (!$task->enabled || !$task->runsIn($this->environment)) {
                continue;
            }

            $event = $this->schedule
                ->command(RunTaskCommand::NAME, [$task->id, '--scheduled-at=' . $tickAt->format(DATE_ATOM)])
                ->cron($task->cron->expression)
                ->name('emorfiq:' . $task->id)
                ->description($task->description ?? $task->id);

            if ($task->timezone !== null) {
                $event->timezone($task->timezone);
            }
            if ($task->policy->runInBackground) {
                $event->runInBackground();
            }
        }
    }

    public function tick(): int
    {
        return $this->artisan->call('schedule:run');
    }
}
