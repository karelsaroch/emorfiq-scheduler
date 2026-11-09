<?php

declare(strict_types=1);

namespace Emorfiq\E2E\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class ReportCommand extends Command
{
    protected $signature = 'e2e:report';

    protected $description = 'Show what ran where, per scheduled minute.';

    public function handle(): int
    {
        $rows = DB::table('e2e_results')->orderBy('scheduled_at')->orderBy('task_id')->orderBy('host')->get();

        $this->table(
            ['minute', 'task', 'host', 'status', 'duration', 'error'],
            $rows->map(static fn (object $r): array => [
                substr((string) $r->scheduled_at, 11, 5),
                $r->task_id,
                $r->host,
                $r->status,
                $r->started_at_ms !== null ? sprintf('%.1fs', ($r->finished_at_ms - $r->started_at_ms) / 1000) : '',
                (string) $r->error,
            ])->all(),
        );

        return self::SUCCESS;
    }
}
