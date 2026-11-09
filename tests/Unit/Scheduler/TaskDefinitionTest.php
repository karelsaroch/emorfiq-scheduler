<?php

declare(strict_types=1);

namespace Emorfiq\Tests\Unit\Scheduler;

use Emorfiq\Scheduler\ExecutionPolicy;
use Emorfiq\Scheduler\TaskDefinition;
use Emorfiq\Tests\Support\RecordingHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TaskDefinitionTest extends TestCase
{
    public function testFluentApiIsImmutable(): void
    {
        $base = TaskDefinition::cron('report.daily', '0 6 * * *', RecordingHandler::class);
        $configured = $base
            ->withParameters(['batch' => 100])
            ->withPolicy(ExecutionPolicy::clusterExclusive(900))
            ->withTimezone('Europe/Prague')
            ->onlyIn('production', 'staging')
            ->withDescription('Daily report');

        self::assertSame([], $base->parameters);
        self::assertNull($base->timezone);
        self::assertSame(['batch' => 100], $configured->parameters);
        self::assertTrue($configured->policy->onOneServer);
        self::assertSame(900, $configured->policy->lockTtlSeconds);
        self::assertSame('Europe/Prague', $configured->timezone?->getName());
        self::assertSame(['production', 'staging'], $configured->environments);
        self::assertSame('Daily report', $configured->description);
        self::assertSame('0 6 * * *', $configured->cron->expression);
    }

    public function testEnvironmentFilter(): void
    {
        $everywhere = TaskDefinition::cron('a', '* * * * *', RecordingHandler::class);
        $prodOnly = $everywhere->onlyIn('production');

        self::assertTrue($everywhere->runsIn('local'));
        self::assertTrue($prodOnly->runsIn('production'));
        self::assertFalse($prodOnly->runsIn('local'));
    }

    public function testIsDueRespectsTimezoneAndEnabled(): void
    {
        $task = TaskDefinition::cron('ny', '0 9 * * *', RecordingHandler::class)->withTimezone('America/New_York');
        $utc = new \DateTimeZone('UTC');

        self::assertTrue($task->isDue(new \DateTimeImmutable('2026-11-09 14:00', $utc)));
        self::assertFalse($task->isDue(new \DateTimeImmutable('2026-11-09 09:00', $utc)));
        self::assertFalse($task->disabled()->isDue(new \DateTimeImmutable('2026-11-09 14:00', $utc)));
        self::assertSame('2026-11-10 09:00', $task->nextRunAt(new \DateTimeImmutable('2026-11-09 14:00', $utc))->format('Y-m-d H:i'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidDefinitions(): iterable
    {
        yield 'uppercase id' => ['Bad', RecordingHandler::class];
        yield 'space in id' => ['bad id', RecordingHandler::class];
        yield 'empty id' => ['', RecordingHandler::class];
        yield 'not a handler' => ['ok', \stdClass::class];
        yield 'missing class' => ['ok', 'App\\Missing'];
    }

    #[DataProvider('invalidDefinitions')]
    public function testRejectsInvalidDefinition(string $id, string $handler): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TaskDefinition::cron($id, '* * * * *', $handler);
    }

    public function testPolicyValidation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ExecutionPolicy(lockTtlSeconds: 0);
    }
}
