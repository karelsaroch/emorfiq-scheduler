<?php

declare(strict_types=1);

namespace Emorfiq\Tests\Unit\Scheduler;

use Emorfiq\Mutex\Mutex;
use Emorfiq\Mutex\Store\InMemoryLockStore;
use Emorfiq\Scheduler\Exception\DuplicateTaskException;
use Emorfiq\Scheduler\Exception\TaskNotFoundException;
use Emorfiq\Scheduler\ExecutionPolicy;
use Emorfiq\Scheduler\HandlerResolverInterface;
use Emorfiq\Scheduler\TaskContext;
use Emorfiq\Scheduler\TaskDefinition;
use Emorfiq\Scheduler\TaskHandlerInterface;
use Emorfiq\Scheduler\TaskRegistry;
use Emorfiq\Scheduler\TaskRunner;
use Emorfiq\Scheduler\TaskRunStatus;
use Emorfiq\Tests\Support\FailingHandler;
use Emorfiq\Tests\Support\FakeClock;
use Emorfiq\Tests\Support\RecordingHandler;
use PHPUnit\Framework\TestCase;

final class TaskRunnerTest extends TestCase
{
    private FakeClock $clock;
    private InMemoryLockStore $store;
    private TaskRegistry $registry;

    protected function setUp(): void
    {
        RecordingHandler::reset();
        $this->clock = new FakeClock(new \DateTimeImmutable('2026-11-09 10:00:07'));
        $this->store = new InMemoryLockStore($this->clock);
        $this->registry = new TaskRegistry();
    }

    private function runner(string $host = 'web1'): TaskRunner
    {
        $resolver = new class () implements HandlerResolverInterface {
            public function resolve(string $handlerClass): TaskHandlerInterface
            {
                return new $handlerClass();
            }
        };

        return new TaskRunner($this->registry, $resolver, new Mutex($this->store), $this->clock, hostname: $host);
    }

    public function testRunsHandlerWithContext(): void
    {
        $this->registry->add(TaskDefinition::cron('report', '0 10 * * *', RecordingHandler::class)->withParameters(['who' => 'A']));

        $result = $this->runner()->run('report');

        self::assertSame(TaskRunStatus::Succeeded, $result->status);
        self::assertSame(0, $result->exitCode());
        self::assertCount(1, RecordingHandler::$contexts);
        $context = RecordingHandler::$contexts[0];
        self::assertSame('A', $context->parameter('who'));
        self::assertSame('fallback', $context->parameter('missing', 'fallback'));
        self::assertSame('2026-11-09 10:00:00', $context->scheduledAt->format('Y-m-d H:i:s'), 'scheduledAt is truncated to the minute');
        self::assertSame($result->runId, $context->runId);
        self::assertNotNull($result->durationMs());
    }

    public function testUnknownTaskThrows(): void
    {
        $this->expectException(TaskNotFoundException::class);
        $this->runner()->run('missing');
    }

    public function testDuplicateRegistrationThrows(): void
    {
        $this->registry->add(TaskDefinition::cron('a', '* * * * *', RecordingHandler::class));

        $this->expectException(DuplicateTaskException::class);
        $this->registry->add(TaskDefinition::cron('a', '* * * * *', RecordingHandler::class));
    }

    public function testDisabledTaskIsSkipped(): void
    {
        $this->registry->add(TaskDefinition::cron('off', '* * * * *', RecordingHandler::class)->disabled());

        self::assertSame(TaskRunStatus::SkippedDisabled, $this->runner()->run('off')->status);
        self::assertSame([], RecordingHandler::$contexts);
    }

    public function testWithoutOverlappingIsPerServerByDefault(): void
    {
        $this->registry->add(TaskDefinition::cron('job', '* * * * *', RecordingHandler::class));
        $web1 = $this->runner('web1');
        $web2 = $this->runner('web2');
        $nested = [];

        RecordingHandler::$during = static function () use ($web1, $web2, &$nested): void {
            RecordingHandler::$during = null;
            $nested = [$web1->run('job')->status, $web2->run('job')->status];
        };
        $outer = $web1->run('job');

        self::assertSame(TaskRunStatus::Succeeded, $outer->status);
        self::assertSame([TaskRunStatus::SkippedOverlapping, TaskRunStatus::Succeeded], $nested);
        self::assertSame(TaskRunStatus::Succeeded, $web1->run('job')->status, 'lock released after run');
    }

    public function testOnOneServerRunsEachOccurrenceOnce(): void
    {
        $this->registry->add(TaskDefinition::cron('cluster', '* * * * *', RecordingHandler::class)->withPolicy(ExecutionPolicy::clusterExclusive()));
        $minute = new \DateTimeImmutable('2026-11-09 10:05');

        self::assertSame(TaskRunStatus::Succeeded, $this->runner('web1')->run('cluster', $minute)->status);
        self::assertSame(TaskRunStatus::SkippedAlreadyHandled, $this->runner('web2')->run('cluster', $minute)->status);
        self::assertSame(TaskRunStatus::SkippedAlreadyHandled, $this->runner('web1')->run('cluster', $minute->modify('+20 seconds'))->status);
        self::assertSame(TaskRunStatus::Succeeded, $this->runner('web2')->run('cluster', $minute->modify('+1 minute'))->status);
    }

    public function testOnOneServerPreventsOverlapAcrossCluster(): void
    {
        $this->registry->add(TaskDefinition::cron('cluster', '* * * * *', RecordingHandler::class)->withPolicy(ExecutionPolicy::clusterExclusive()));
        $web2 = $this->runner('web2');
        $nested = null;

        RecordingHandler::$during = static function () use ($web2, &$nested): void {
            RecordingHandler::$during = null;
            $nested = $web2->run('cluster', new \DateTimeImmutable('2026-11-09 10:06'))->status; // další minuta
        };
        $this->runner('web1')->run('cluster', new \DateTimeImmutable('2026-11-09 10:05'));

        self::assertSame(TaskRunStatus::SkippedOverlapping, $nested);
    }

    public function testAllowOverlapping(): void
    {
        $this->registry->add(TaskDefinition::cron('free', '* * * * *', RecordingHandler::class)->withPolicy(ExecutionPolicy::allowOverlapping()));
        $runner = $this->runner();
        $nested = null;

        RecordingHandler::$during = static function () use ($runner, &$nested): void {
            RecordingHandler::$during = null;
            $nested = $runner->run('free')->status;
        };
        $runner->run('free');

        self::assertSame(TaskRunStatus::Succeeded, $nested);
    }

    public function testFailureIsCapturedAndLockReleased(): void
    {
        $this->registry->add(TaskDefinition::cron('boom', '* * * * *', FailingHandler::class));

        $result = $this->runner()->run('boom');

        self::assertSame(TaskRunStatus::Failed, $result->status);
        self::assertSame(1, $result->exitCode());
        self::assertSame('Task failed on purpose', $result->error?->getMessage());
        self::assertSame(TaskRunStatus::Failed, $this->runner()->run('boom')->status, 'lock was released, task runs again');
    }

    public function testHeartbeatExtendsLockAndReportsLoss(): void
    {
        $this->registry->add(TaskDefinition::cron('long', '* * * * *', RecordingHandler::class)
            ->withPolicy(new ExecutionPolicy(lockTtlSeconds: 60)));
        $clock = $this->clock;
        $runner = $this->runner();
        $observed = [];

        RecordingHandler::$during = static function (TaskContext $context) use ($clock, $runner, &$observed): void {
            RecordingHandler::$during = null;
            $clock->advanceMs(50_000);
            $observed[] = $context->heartbeat();          // prodlouží o dalších 60 s
            $clock->advanceMs(50_000);                   // 100 s od startu, zámek stále náš
            $observed[] = $runner->run('long')->status;
            $clock->advanceMs(70_000);                   // vypršel
            $observed[] = $context->heartbeat();
        };
        $runner->run('long');

        self::assertSame([true, TaskRunStatus::SkippedOverlapping, false], $observed);
    }

    public function testDueFiltersByEnvironmentAndCron(): void
    {
        $this->registry->add(TaskDefinition::cron('every', '* * * * *', RecordingHandler::class));
        $this->registry->add(TaskDefinition::cron('ten', '0 10 * * *', RecordingHandler::class));
        $this->registry->add(TaskDefinition::cron('eleven', '0 11 * * *', RecordingHandler::class));
        $this->registry->add(TaskDefinition::cron('prod', '* * * * *', RecordingHandler::class)->onlyIn('production'));
        $this->registry->add(TaskDefinition::cron('off', '* * * * *', RecordingHandler::class)->disabled());

        $ids = array_map(static fn (TaskDefinition $t) => $t->id, $this->registry->due(new \DateTimeImmutable('2026-11-09 10:00'), 'local'));

        self::assertSame(['every', 'ten'], $ids);
    }
}
