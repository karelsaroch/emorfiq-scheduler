<?php

declare(strict_types=1);

namespace Emorfiq\Tests\Laravel;

use Emorfiq\Bridge\Laravel\SchedulerServiceProvider;
use Emorfiq\Tests\Laravel\Fixtures\TestTasks;
use Emorfiq\Tests\Support\RecordingHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Orchestra\Testbench\TestCase as Testbench;

abstract class TestCase extends Testbench
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        RecordingHandler::reset();
        parent::setUp();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function getPackageProviders($app): array
    {
        return [SchedulerServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('scheduler.mutex.store', 'database');
        $app['config']->set('scheduler.mutex.database.connection', 'testing');
        $app->tag([TestTasks::class], SchedulerServiceProvider::TASK_PROVIDER_TAG);
    }
}
