<?php

declare(strict_types=1);

namespace Emorfiq\E2E;

use Emorfiq\E2E\Handler\FailingHandler;
use Emorfiq\E2E\Handler\SleepHandler;
use Emorfiq\Scheduler\ExecutionPolicy;
use Emorfiq\Scheduler\TaskDefinition;
use Emorfiq\Scheduler\TaskProviderInterface;

/**
 * Úlohy, na kterých e2e ověřuje chování ve 2uzlovém clusteru.
 */
final class E2ETasks implements TaskProviderInterface
{
    public const PER_SERVER = 'e2e.per-server';
    public const CLUSTER_ONCE = 'e2e.cluster-once';
    public const LONG_EXCLUSIVE = 'e2e.long-exclusive';
    public const EVEN_MINUTES = 'e2e.even-minutes';
    public const FAILING = 'e2e.failing';
    public const PROD_ONLY = 'e2e.prod-only';

    public function tasks(): iterable
    {
        // Každou minutu na každém serveru (zákaz souběhu jen v rámci serveru).
        yield TaskDefinition::cron(self::PER_SERVER, '* * * * *', SleepHandler::class)
            ->withParameters(['seconds' => 2])
            ->withDescription('Runs on every node every minute');

        // Každou minutu, ale v clusteru jen jednou.
        yield TaskDefinition::cron(self::CLUSTER_ONCE, '* * * * *', SleepHandler::class)
            ->withParameters(['seconds' => 1])
            ->withPolicy(ExecutionPolicy::clusterExclusive(lockTtlSeconds: 60))
            ->withDescription('Once per minute in the whole cluster');

        // Trvá 80 s, plánuje se každou minutu → musí docházet k přeskočení, nikdy k souběhu.
        yield TaskDefinition::cron(self::LONG_EXCLUSIVE, '* * * * *', SleepHandler::class)
            ->withParameters(['seconds' => 80])
            ->withPolicy(ExecutionPolicy::clusterExclusive(lockTtlSeconds: 30)) // kratší než běh → drží ho heartbeat
            ->withDescription('Long task, never overlaps, heartbeat keeps the lock');

        yield TaskDefinition::cron(self::EVEN_MINUTES, '*/2 * * * *', SleepHandler::class)
            ->withPolicy(ExecutionPolicy::clusterExclusive())
            ->withDescription('Every even minute, once in cluster');

        yield TaskDefinition::cron(self::FAILING, '* * * * *', FailingHandler::class)
            ->withPolicy(ExecutionPolicy::clusterExclusive())
            ->withDescription('Always fails');

        yield TaskDefinition::cron(self::PROD_ONLY, '* * * * *', SleepHandler::class)
            ->onlyIn('production')
            ->withDescription('Must never run in e2e environment');
    }
}
