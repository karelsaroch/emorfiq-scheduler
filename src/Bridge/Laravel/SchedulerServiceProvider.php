<?php

declare(strict_types=1);

namespace Emorfiq\Bridge\Laravel;

use Emorfiq\Mutex\LockStoreInterface;
use Emorfiq\Mutex\Mutex;
use Emorfiq\Mutex\MutexInterface;
use Emorfiq\Mutex\Store\FlockLockStore;
use Emorfiq\Mutex\Store\PdoLockStore;
use Emorfiq\Mutex\Store\RedisLockStore;
use Emorfiq\Scheduler\ContainerHandlerResolver;
use Emorfiq\Scheduler\HandlerResolverInterface;
use Emorfiq\Scheduler\SchedulerInterface;
use Emorfiq\Scheduler\TaskRegistry;
use Emorfiq\Scheduler\TaskRegistryInterface;
use Emorfiq\Scheduler\TaskRunner;
use Emorfiq\Scheduler\TaskRunnerInterface;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Jediné místo, kde se systém "dozví" o Laravelu. Přechod na jiný engine
 * = změna bindingu SchedulerInterface (+ odebrání tohoto provideru).
 *
 * Moduly registrují úlohy otagováním svých TaskProviderInterface:
 *   $this->app->tag([BillingTasks::class], SchedulerServiceProvider::TASK_PROVIDER_TAG);
 */
final class SchedulerServiceProvider extends ServiceProvider
{
    public const TASK_PROVIDER_TAG = 'emorfiq.scheduler.task_provider';

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../../config/scheduler.php', 'scheduler');

        $this->app->singleton(ClockInterface::class, LaravelClock::class);

        $this->app->singleton(LockStoreInterface::class, function (Application $app): LockStoreInterface {
            $config = $app['config']->get('scheduler.mutex');

            return match ($config['store']) {
                'database' => new PdoLockStore(
                    $app['db']->connection($config['database']['connection'])->getPdo(),
                    $config['database']['table'],
                    $app->make(ClockInterface::class),
                ),
                'redis' => new RedisLockStore(
                    $app['redis']->connection($config['redis']['connection'])->client(),
                ),
                'flock' => new FlockLockStore($config['flock']['directory']),
                default => throw new \InvalidArgumentException(sprintf('Unknown mutex store "%s".', $config['store'])),
            };
        });

        $this->app->singleton(MutexInterface::class, fn (Application $app): MutexInterface => new Mutex(
            $app->make(LockStoreInterface::class),
            (string) $app['config']->get('scheduler.mutex.prefix'),
        ));

        $this->app->singleton(TaskRegistryInterface::class, fn (Application $app): TaskRegistryInterface => new TaskRegistry(
            $app->tagged(self::TASK_PROVIDER_TAG),
        ));

        $this->app->singleton(HandlerResolverInterface::class, fn (Application $app) => new ContainerHandlerResolver($app));

        $this->app->singleton(TaskRunnerInterface::class, fn (Application $app): TaskRunnerInterface => new TaskRunner(
            $app->make(TaskRegistryInterface::class),
            $app->make(HandlerResolverInterface::class),
            $app->make(MutexInterface::class),
            $app->make(ClockInterface::class),
            $app->make(LoggerInterface::class),
            (string) gethostname(),
        ));

        $this->app->singleton(SchedulerInterface::class, fn (Application $app): SchedulerInterface => $this->makeLaravelScheduler(
            $app,
            $app->make(Schedule::class),
        ));
    }

    public function boot(): void
    {
        if (!$this->app->runningInConsole()) {
            return;
        }

        $this->commands([RunTaskCommand::class, TickCommand::class]);
        $this->publishes([__DIR__ . '/../../../config/scheduler.php' => config_path('scheduler.php')], 'scheduler-config');
        $this->loadMigrationsFrom(__DIR__ . '/../../../database/migrations');

        // Laravel sestavuje Schedule při `schedule:run` / `schedule:list` → v ten okamžik předáme úlohy.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule, Application $app): void {
            $this->makeLaravelScheduler($app, $schedule)->register(
                ...$app->make(TaskRegistryInterface::class)->all(),
            );
        });
    }

    private function makeLaravelScheduler(Application $app, Schedule $schedule): LaravelScheduler
    {
        return new LaravelScheduler(
            $schedule,
            $app->make(\Illuminate\Contracts\Console\Kernel::class),
            $app->make(ClockInterface::class),
            $app->environment(),
        );
    }
}
