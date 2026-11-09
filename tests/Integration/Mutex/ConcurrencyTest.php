<?php

declare(strict_types=1);

namespace Emorfiq\Tests\Integration\Mutex;

use Emorfiq\Tests\Support\Backends;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Skutečný souběh: N samostatných PHP procesů nad stejným úložištěm.
 */
final class ConcurrencyTest extends TestCase
{
    private const PROCESSES = 12;

    /** @return iterable<string, array{string}> */
    public static function backends(): iterable
    {
        foreach (['mysql', 'pgsql', 'redis', 'flock', 'sqlite-file'] as $backend) {
            yield $backend => [$backend];
        }
    }

    #[DataProvider('backends')]
    public function testExactlyOneProcessWinsSimultaneousAcquire(string $backend): void
    {
        $this->requireBackend($backend);
        $key = 'race-' . bin2hex(random_bytes(4));
        $startAt = sprintf('%.6f', microtime(true) + 1.5);

        $outputs = $this->runParallel(array_fill(0, self::PROCESSES, ['try-once.php', $backend, $key, $startAt, '1000']));

        self::assertSame(1, array_sum(array_map('intval', $outputs)), 'exactly one winner: ' . implode(',', $outputs));
    }

    #[DataProvider('backends')]
    public function testMutualExclusionUnderContention(string $backend): void
    {
        $this->requireBackend($backend);
        $iterations = 15;
        $key = 'counter-' . bin2hex(random_bytes(4));
        $counterFile = tempnam(sys_get_temp_dir(), 'emorfiq-counter-');
        file_put_contents($counterFile, '0');
        $startAt = sprintf('%.6f', microtime(true) + 1.5);

        try {
            $outputs = $this->runParallel(array_fill(0, self::PROCESSES, ['contend.php', $backend, $key, $counterFile, (string) $iterations, $startAt]));

            $expected = self::PROCESSES * $iterations;
            self::assertSame(array_fill(0, self::PROCESSES, (string) $iterations), $outputs, 'every process finished all iterations');
            self::assertSame((string) $expected, file_get_contents($counterFile), 'no lost updates → critical sections never overlapped');
        } finally {
            @unlink($counterFile);
        }
    }

    private function requireBackend(string $backend): void
    {
        if (($reason = Backends::unavailable($backend)) !== null) {
            self::markTestSkipped($reason);
        }
        // Tabulku vytvoří předem jeden proces (CREATE TABLE IF NOT EXISTS v souběhu umí na PG selhat).
        Backends::create($backend);
    }

    /**
     * @param list<list<string>> $commands [script, ...args]
     *
     * @return list<string> stdout každého procesu
     */
    private function runParallel(array $commands): array
    {
        $processes = [];
        foreach ($commands as $i => $command) {
            $script = array_shift($command);
            $process = proc_open(
                [PHP_BINARY, dirname(__DIR__, 2) . '/bin/' . $script, ...$command],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
            );
            self::assertIsResource($process);
            $processes[$i] = [$process, $pipes];
        }

        $outputs = [];
        foreach ($processes as $i => [$process, $pipes]) {
            $outputs[$i] = trim((string) stream_get_contents($pipes[1]));
            $stderr = trim((string) stream_get_contents($pipes[2]));
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exit = proc_close($process);
            self::assertSame(0, $exit, "process $i failed: $stderr");
        }

        return $outputs;
    }
}
