<?php

declare(strict_types=1);

namespace Emorfiq\Scheduler;

/**
 * Port na plánovací engine (Laravel Scheduler, crunz, vlastní…).
 *
 * Engine má JEDINOU odpovědnost: v čase daném cronem spustit
 * `<console> scheduler:run-task <id> --scheduled-at=<ISO8601>`.
 * Vše ostatní (zámky, logování, chyby, DI) řeší engine-agnostický {@see TaskRunnerInterface}.
 */
interface SchedulerInterface
{
    /**
     * Předá úlohy enginu. Volá se při každém startu enginu (každý tick),
     * nefiltruje se tu podle splatnosti – to dělá engine sám podle cronu.
     * Úlohy vypnuté nebo mimo aktuální prostředí adaptér přeskočí.
     */
    public function register(TaskDefinition ...$tasks): void;

    /**
     * Jeden tick enginu – volá ho systémový cron každou minutu přes
     * `* * * * * php <console> scheduler:tick`. Díky tomu se crontab na serveru
     * při výměně enginu nemění.
     *
     * @return int Exit code (0 = OK).
     */
    public function tick(): int;
}
