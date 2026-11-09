<?php

declare(strict_types=1);

namespace Emorfiq\E2E\Console;

use Emorfiq\E2E\E2ETasks;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Ověří invarianty clusteru nad zaznamenanými výsledky.
 */
final class AssertCommand extends Command
{
    protected $signature = 'e2e:assert
        {--min-minutes=3 : Minimální počet pozorovaných minut}
        {--nodes=node1,node2 : Očekávané uzly}';

    protected $description = 'Verify scheduler invariants on recorded e2e results.';

    /** @var list<string> */
    private array $failures = [];

    public function handle(): int
    {
        $nodes = explode(',', (string) $this->option('nodes'));
        $all = DB::table('e2e_results')->get();

        // Poslední minuta může být ještě rozpracovaná → hodnotíme jen uzavřené minuty.
        $minutes = $all->pluck('scheduled_at')->unique()->sort()->values();
        $closed = $minutes->slice(0, -1)->values();
        $this->line(sprintf('Observed minutes: %s (evaluated: %d)', $minutes->map(fn ($m) => substr((string) $m, 11, 5))->implode(', '), $closed->count()));
        $this->check($closed->count() >= (int) $this->option('min-minutes'), sprintf('at least %s closed minutes observed', $this->option('min-minutes')));

        $rows = $all->whereIn('scheduled_at', $closed->all());
        $byTask = static fn (string $task): Collection => $rows->where('task_id', $task);

        // 1) per-server: každá minuta, každý uzel, úspěch
        foreach ($closed as $minute) {
            $hosts = $byTask(E2ETasks::PER_SERVER)->where('scheduled_at', $minute)->where('status', 'succeeded')->pluck('host')->sort()->values()->all();
            $this->check($hosts === $nodes, sprintf('%s @ %s ran on every node [%s]', E2ETasks::PER_SERVER, substr((string) $minute, 11, 5), implode(',', $hosts)));
        }

        // 2) cluster-once: každá minuta právě 1× úspěch, ostatní uzly skipped_already_handled
        foreach ($closed as $minute) {
            $r = $byTask(E2ETasks::CLUSTER_ONCE)->where('scheduled_at', $minute);
            $this->check(
                $r->where('status', 'succeeded')->count() === 1 && $r->where('status', 'skipped_already_handled')->count() === count($nodes) - 1,
                sprintf('%s @ %s ran exactly once [%s]', E2ETasks::CLUSTER_ONCE, substr((string) $minute, 11, 5), $r->map(fn ($x) => "$x->host:$x->status")->implode(', ')),
            );
        }

        // 3) even-minutes: jen sudé minuty, v každé sudé právě jednou
        $even = $byTask(E2ETasks::EVEN_MINUTES);
        $this->check($even->every(fn ($r) => (int) substr((string) $r->scheduled_at, 14, 2) % 2 === 0), E2ETasks::EVEN_MINUTES . ' only in even minutes');
        foreach ($closed->filter(fn ($m) => (int) substr((string) $m, 14, 2) % 2 === 0) as $minute) {
            $this->check($even->where('scheduled_at', $minute)->where('status', 'succeeded')->count() === 1, sprintf('%s @ %s ran exactly once', E2ETasks::EVEN_MINUTES, substr((string) $minute, 11, 5)));
        }

        // 4) long-exclusive: dokončené běhy se nikdy nepřekrývají; souběh vedl k přeskočení
        $long = $all->where('task_id', E2ETasks::LONG_EXCLUSIVE);
        $intervals = $long->where('status', 'succeeded')->sortBy('started_at_ms')->values();
        $overlaps = 0;
        for ($i = 1; $i < $intervals->count(); ++$i) {
            $overlaps += (int) ($intervals[$i]->started_at_ms < $intervals[$i - 1]->finished_at_ms);
        }
        $this->check($intervals->isNotEmpty(), E2ETasks::LONG_EXCLUSIVE . ' completed at least once (' . $intervals->count() . ')');
        $this->check($overlaps === 0, E2ETasks::LONG_EXCLUSIVE . ' never overlapped (overlaps: ' . $overlaps . ')');
        $this->check($long->where('status', 'skipped_overlapping')->isNotEmpty(), E2ETasks::LONG_EXCLUSIVE . ' was skipped while running');
        $this->check($long->where('status', 'failed')->isEmpty(), E2ETasks::LONG_EXCLUSIVE . ' never lost its lock (heartbeat > TTL)');

        // 5) failing: selhání zaznamenáno, 1× za minutu
        foreach ($closed as $minute) {
            $this->check($byTask(E2ETasks::FAILING)->where('scheduled_at', $minute)->where('status', 'failed')->count() === 1, sprintf('%s @ %s failed once', E2ETasks::FAILING, substr((string) $minute, 11, 5)));
        }

        // 6) prod-only: v prostředí e2e se nesmí plánovat vůbec
        $this->check($all->where('task_id', E2ETasks::PROD_ONLY)->isEmpty(), E2ETasks::PROD_ONLY . ' never scheduled outside production');

        // 7) po doběhnutí nesmí zůstat běhové zámky (zámky výskytu ano – vyprší)
        $stale = DB::connection('locks')->table('scheduler_locks')->where('lock_key', 'like', '%scheduler:run:%')
            ->where('lock_key', 'not like', '%' . E2ETasks::LONG_EXCLUSIVE . '%')->count();
        $this->check($stale === 0, 'no leftover run locks for short tasks (' . $stale . ')');

        $this->newLine();
        if ($this->failures !== []) {
            $this->error(sprintf('%d check(s) FAILED', count($this->failures)));

            return self::FAILURE;
        }
        $this->info('ALL E2E CHECKS PASSED');

        return self::SUCCESS;
    }

    private function check(bool $ok, string $label): void
    {
        $this->line(($ok ? '<info>  OK  </info> ' : '<error> FAIL </error> ') . $label);
        if (!$ok) {
            $this->failures[] = $label;
        }
    }
}
