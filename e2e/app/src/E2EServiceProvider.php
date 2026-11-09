<?php

declare(strict_types=1);

namespace Emorfiq\E2E;

use Emorfiq\Bridge\Laravel\SchedulerServiceProvider;
use Emorfiq\E2E\Console\AssertCommand;
use Emorfiq\E2E\Console\ReportCommand;
use Emorfiq\E2E\Console\ResetCommand;
use Emorfiq\Scheduler\TaskRunnerInterface;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

final class E2EServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Doporučené vyhrazené DB spojení pro zámky (autocommit, mimo aplikační transakce).
        $this->app['config']->set('database.connections.locks', $this->app['config']->get('database.connections.mysql'));

        $this->app->tag([E2ETasks::class], SchedulerServiceProvider::TASK_PROVIDER_TAG);

        // Dekorátor: každý výsledek běhu (i přeskočení) se zapíše do e2e_results.
        $this->app->extend(TaskRunnerInterface::class, fn (TaskRunnerInterface $runner, Application $app) => new RecordingTaskRunner(
            $runner,
            $app['db']->connection(),
        ));
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(dirname(__DIR__) . '/database/migrations');
        $this->commands([ResetCommand::class, ReportCommand::class, AssertCommand::class]);
    }
}
