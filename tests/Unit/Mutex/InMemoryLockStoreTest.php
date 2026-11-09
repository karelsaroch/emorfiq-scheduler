<?php

declare(strict_types=1);

namespace Emorfiq\Tests\Unit\Mutex;

use Emorfiq\Mutex\LockStoreInterface;
use Emorfiq\Mutex\Store\InMemoryLockStore;
use Emorfiq\Tests\Support\FakeClock;
use Emorfiq\Tests\Support\LockStoreContractTestCase;

final class InMemoryLockStoreTest extends LockStoreContractTestCase
{
    protected function createStore(FakeClock $clock): LockStoreInterface
    {
        return new InMemoryLockStore($clock);
    }
}
