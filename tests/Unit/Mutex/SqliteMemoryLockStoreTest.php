<?php

declare(strict_types=1);

namespace Emorfiq\Tests\Unit\Mutex;

use Emorfiq\Mutex\LockStoreInterface;
use Emorfiq\Mutex\Store\PdoLockStore;
use Emorfiq\Tests\Support\FakeClock;
use Emorfiq\Tests\Support\LockSchema;
use Emorfiq\Tests\Support\LockStoreContractTestCase;

final class SqliteMemoryLockStoreTest extends LockStoreContractTestCase
{
    protected function createStore(FakeClock $clock): LockStoreInterface
    {
        $pdo = new \PDO('sqlite::memory:');
        LockSchema::install($pdo);

        return new PdoLockStore($pdo, 'scheduler_locks', $clock);
    }

    public function testRejectsInvalidTableName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PdoLockStore(new \PDO('sqlite::memory:'), 'locks; DROP TABLE users');
    }

    public function testPurgeExpired(): void
    {
        $store = $this->store;
        self::assertInstanceOf(PdoLockStore::class, $store);
        $this->mutex->acquire('a', 1);
        $this->mutex->acquire('b', 10);
        $this->elapse(2000);

        self::assertSame(1, $store->purgeExpired());
    }
}
