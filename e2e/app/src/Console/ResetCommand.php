<?php

declare(strict_types=1);

namespace Emorfiq\E2E\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class ResetCommand extends Command
{
    protected $signature = 'e2e:reset';

    protected $description = 'Clear e2e results and scheduler locks.';

    public function handle(): int
    {
        DB::table('e2e_results')->truncate();
        DB::table('scheduler_locks')->truncate();
        $this->info('e2e state reset');

        return self::SUCCESS;
    }
}
