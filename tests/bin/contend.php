<?php

declare(strict_types=1);

/**
 * Pomocný proces pro ConcurrencyTest.
 *
 *   php contend.php <backend> <key> <counterFile> <iterations> <startAtUnixFloat>
 *
 * Počká na společný start, pak $iterations× pod zámkem provede ne-atomický
 * read-modify-write čítače v souboru. Bez fungujícího zámku se přírůstky ztratí.
 */

use Emorfiq\Mutex\Mutex;
use Emorfiq\Tests\Support\Backends;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

[, $backend, $key, $counterFile, $iterations, $startAt] = $argv;

$mutex = new Mutex(Backends::create($backend), 'contend:', retryIntervalMs: 2);

while (microtime(true) < (float) $startAt) {
    usleep(500);
}

$acquired = 0;
for ($i = 0; $i < (int) $iterations; ++$i) {
    $mutex->synchronized($key, static function () use ($counterFile, &$acquired): void {
        $value = (int) file_get_contents($counterFile);
        usleep(random_int(100, 1500)); // zvětší okno pro race condition
        file_put_contents($counterFile, (string) ($value + 1));
        ++$acquired;
    }, ttlSeconds: 30, waitSeconds: 60);
}

echo $acquired;
