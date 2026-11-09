<?php

declare(strict_types=1);

namespace Emorfiq\Tests\Integration\Mutex;

use Emorfiq\Mutex\LockStoreInterface;
use Emorfiq\Tests\Support\Backends;
use Emorfiq\Tests\Support\FakeClock;
use Emorfiq\Tests\Support\LockStoreContractTestCase;

final class PgsqlLockStoreTest extends LockStoreContractTestCase
{
    protected function createStore(FakeClock $clock): LockStoreInterface
    {
        if (($reason = Backends::unavailable('pgsql')) !== null) {
            self::markTestSkipped($reason);
        }

        return Backends::create('pgsql', $clock);
    }
}
