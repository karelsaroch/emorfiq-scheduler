<?php

declare(strict_types=1);

namespace Emorfiq\Tests\Laravel;

use Emorfiq\Bridge\Laravel\LaravelScheduler;
use Emorfiq\Mutex\Mutex;
use Emorfiq\Mutex\MutexInterface;
use Emorfiq\Mutex\Store\PdoLockStore;
use Emorfiq\Mutex\LockStoreInterface;
use Emorfiq\Scheduler\SchedulerInterface;
use Emorfiq\Scheduler\TaskRegistryInterface;
use Emorfiq\Scheduler\TaskRunner;
use Emorfiq\Scheduler\TaskRunnerInterface;
use Emorfiq\Tests\Support\RecordingHandler;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class SchedulerServiceProviderTest extends TestCase
{
    public function testBindsAllPorts(): void
    {
        self::assertInstanceOf(Mutex::class, $this->app->make(MutexInterface::class));
        self::assertInstanceOf(PdoLockStore::class, $this->app->make(LockStoreInterface::class));
        self::assertInstanceOf(TaskRunner::class, $this->app->make(TaskRunnerInterface::class));
        self::assertInstanceOf(LaravelScheduler::class, $this->app->make(SchedulerInterface::class));
        self::assertCount(5, $this->app->make(TaskRegistryInterface::class)->all());
    }

    public function testRegistersTasksIntoLaravelSchedule(): void
    {
        Carbon::setTestNow('2026-11-09 05:59:30');
        $events = $this->scheduledEvents();

        self::assertSame(['report.daily', 'sync.cluster', 'broken'], array_keys($events), 'disabled and other-environment tasks are not scheduled');

        $report = $events['report.daily'];
        self::assertSame('0 6 * * *', $report->expression);
        self::assertSame('Europe/Prague', $report->timezone instanceof \DateTimeZone ? $report->timezone->getName() : $report->timezone);
        self::assertSame('Daily report', $report->description);
        self::assertStringEndsWith("artisan' scheduler:run-task 'report.daily' --scheduled-at=2026-11-09T05:59:00+00:00", (string) $report->command);
        self::assertTrue($report->runInBackground);
        self::assertFalse($events['sync.cluster']->runInBackground);

        // Laravel mutex se nepoužívá – zámky řeší náš TaskRunner.
        foreach ($events as $event) {
            self::assertFalse($event->withoutOverlapping);
            self::assertFalse($event->onOneServer);
        }
    }

    public function testLaravelEvaluatesDueEventsFromOurCron(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-09 06:00:00', 'Europe/Prague'));
        $schedule = $this->app->make(Schedule::class);

        $due = array_map(static fn (Event $e): string => (string) $e->description, $schedule->dueEvents($this->app)->all());

        self::assertSame(['Daily report', 'sync.cluster', 'broken'], array_values($due));
    }

    public function testRunTaskCommandExecutesHandlerUnderDatabaseLock(): void
    {
        $locksDuringRun = null;
        RecordingHandler::$during = static function () use (&$locksDuringRun): void {
            $locksDuringRun = DB::table('scheduler_locks')->pluck('lock_key')->all();
        };

        $this->artisan('scheduler:run-task', ['task' => 'report.daily', '--scheduled-at' => '2026-11-09T06:00:00+01:00'])
            ->expectsOutputToContain('[succeeded] report.daily')
            ->assertExitCode(0);

        self::assertCount(1, RecordingHandler::$contexts);
        self::assertSame('pdf', RecordingHandler::$contexts[0]->parameter('format'));
        self::assertSame('2026-11-09T06:00:00+01:00', RecordingHandler::$contexts[0]->scheduledAt->format(DATE_ATOM));
        self::assertSame([$this->prefix() . 'scheduler:run:report.daily:' . gethostname()], $locksDuringRun);
        self::assertSame(0, DB::table('scheduler_locks')->count(), 'run lock released after the run');
    }

    public function testOnOneServerOccurrenceLockStaysInDatabase(): void
    {
        $args = ['task' => 'sync.cluster', '--scheduled-at' => '2026-11-09T10:05:00+01:00'];

        $this->artisan('scheduler:run-task', $args)->expectsOutputToContain('[succeeded]')->assertExitCode(0);
        $this->artisan('scheduler:run-task', $args)->expectsOutputToContain('[skipped_already_handled]')->assertExitCode(0);

        self::assertSame([$this->prefix() . 'scheduler:occurrence:sync.cluster:202611091005'], DB::table('scheduler_locks')->pluck('lock_key')->all());
    }

    public function testFailingTaskReturnsNonZeroExitCode(): void
    {
        $this->artisan('scheduler:run-task', ['task' => 'broken'])
            ->expectsOutputToContain('Task failed on purpose')
            ->assertExitCode(1);
    }

    public function testUnknownTaskIsInvalid(): void
    {
        $this->artisan('scheduler:run-task', ['task' => 'does-not-exist'])->assertExitCode(2);
    }

    public function testMutexStoreIsConfigurable(): void
    {
        $this->app['config']->set('scheduler.mutex.store', 'flock');
        $this->app->forgetInstance(LockStoreInterface::class);

        self::assertInstanceOf(\Emorfiq\Mutex\Store\FlockLockStore::class, $this->app->make(LockStoreInterface::class));
    }

    private function prefix(): string
    {
        return (string) $this->app['config']->get('scheduler.mutex.prefix');
    }

    /** @return array<string, Event> keyed by task id */
    private function scheduledEvents(): array
    {
        $events = [];
        foreach ($this->app->make(Schedule::class)->events() as $event) {
            if (preg_match("/scheduler:run-task '([^']+)'/", (string) $event->command, $m)) {
                $events[$m[1]] = $event;
            }
        }

        return $events;
    }
}
