<?php

declare(strict_types=1);

namespace Emorfiq\Tests\Integration\Mutex;

use Emorfiq\Mutex\LockStoreInterface;
use Emorfiq\Tests\Support\Backends;
use Emorfiq\Tests\Support\FakeClock;
use Emorfiq\Tests\Support\LockStoreContractTestCase;

final class RedisLockStoreTest extends LockStoreContractTestCase
{
    protected function createStore(FakeClock $clock): LockStoreInterface
    {
        if (($reason = Backends::unavailable('redis')) !== null) {
            self::markTestSkipped($reason);
        }

        return Backends::create('redis', $clock);
    }

    /** Redis měří TTL sám – čas nelze podvrhnout, skutečně čekáme. */
    protected function elapse(int $ms): void
    {
        usleep($ms * 1000);
    }
}
