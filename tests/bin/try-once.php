<?php

declare(strict_types=1);

/**
 * php try-once.php <backend> <key> <startAtUnixFloat> <holdMs>
 * Jediný pokus o zámek ve společný okamžik; vypíše 1 (získal) / 0.
 */

use Emorfiq\Mutex\Mutex;
use Emorfiq\Tests\Support\Backends;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

[, $backend, $key, $startAt, $holdMs] = $argv;

$mutex = new Mutex(Backends::create($backend), 'try-once:');

while (microtime(true) < (float) $startAt) {
    usleep(200);
}

$lock = $mutex->acquire($key, 30);
echo $lock === null ? '0' : '1';
usleep((int) $holdMs * 1000);
$lock?->release();
