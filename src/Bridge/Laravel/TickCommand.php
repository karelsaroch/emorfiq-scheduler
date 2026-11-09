<?php

declare(strict_types=1);

namespace Emorfiq\Bridge\Laravel;

use Emorfiq\Scheduler\SchedulerInterface;
use Illuminate\Console\Command;

/**
 * Crontab: `* * * * * cd /app && php artisan scheduler:tick >> /dev/null 2>&1`
 * Řádek zůstává stejný i po přechodu na crunz/native engine.
 */
final class TickCommand extends Command
{
    protected $signature = 'scheduler:tick';

    protected $description = 'Run one tick of the configured scheduler engine.';

    public function handle(SchedulerInterface $scheduler): int
    {
        return $scheduler->tick();
    }
}
