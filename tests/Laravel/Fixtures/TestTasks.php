<?php

declare(strict_types=1);

namespace Emorfiq\Tests\Laravel\Fixtures;

use Emorfiq\Scheduler\ExecutionPolicy;
use Emorfiq\Scheduler\TaskDefinition;
use Emorfiq\Scheduler\TaskProviderInterface;
use Emorfiq\Tests\Support\FailingHandler;
use Emorfiq\Tests\Support\RecordingHandler;

final class TestTasks implements TaskProviderInterface
{
    public function tasks(): iterable
    {
        yield TaskDefinition::cron('report.daily', '0 6 * * *', RecordingHandler::class)
            ->withParameters(['format' => 'pdf'])
            ->withTimezone('Europe/Prague')
            ->withDescription('Daily report');
        yield TaskDefinition::cron('sync.cluster', '*/5 * * * *', RecordingHandler::class)
            ->withPolicy(new ExecutionPolicy(onOneServer: true, runInBackground: false));
        yield TaskDefinition::cron('broken', '* * * * *', FailingHandler::class);
        yield TaskDefinition::cron('prod.only', '* * * * *', RecordingHandler::class)->onlyIn('production');
        yield TaskDefinition::cron('switched.off', '* * * * *', RecordingHandler::class)->disabled();
    }
}
