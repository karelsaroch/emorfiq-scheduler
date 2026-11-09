<?php

declare(strict_types=1);

namespace Emorfiq\Tests\Support;

use Emorfiq\Mutex\Exception\LockNotAcquiredException;
use Emorfiq\Mutex\LockStoreInterface;
use Emorfiq\Mutex\Mutex;
use PHPUnit\Framework\TestCase;

/**
 * Společný kontrakt, který musí splnit každé úložiště zámků.
 */
abstract class LockStoreContractTestCase extends TestCase
{
    protected FakeClock $clock;
    protected LockStoreInterface $store;
    protected Mutex $mutex;

    abstract protected function createStore(FakeClock $clock): LockStoreInterface;

    /** Úložiště respektuje TTL (flock ne – zámek drží proces). */
    protected function supportsTtl(): bool
    {
        return true;
    }

    /** Posune čas o $ms – výchozí přes FakeClock, úložiště s vlastním časem (Redis) skutečně čeká. */
    protected function elapse(int $ms): void
    {
        $this->clock->advanceMs($ms);
    }

    protected function setUp(): void
    {
        $this->clock = new FakeClock(new \DateTimeImmutable());
        $this->store = $this->createStore($this->clock);
        // Unikátní prefix → testy se navzájem neovlivní ani při sdíleném úložišti.
        $this->mutex = new Mutex($this->store, sprintf('test:%s:%s:', static::class, bin2hex(random_bytes(4))), 10);
    }

    public function testAcquireAndRelease(): void
    {
        $lock = $this->mutex->acquire('job', 5);

        self::assertNotNull($lock);
        self::assertTrue($lock->isHeld());
        self::assertTrue($this->mutex->isLocked('job'));
        self::assertTrue($lock->release());
        self::assertFalse($this->mutex->isLocked('job'));
        self::assertFalse($lock->release(), 'release must be idempotent');
        self::assertFalse($lock->isHeld());
    }

    public function testSecondAcquireFailsWhileHeld(): void
    {
        $first = $this->mutex->acquire('job', 5);

        self::assertNotNull($first);
        self::assertNull($this->mutex->acquire('job', 5));
        self::assertNotNull($this->mutex->acquire('other-job', 5), 'different keys are independent');
    }

    public function testCanReacquireAfterRelease(): void
    {
        $this->mutex->acquire('job', 5)?->release();

        self::assertNotNull($this->mutex->acquire('job', 5));
    }

    public function testRefreshOwnLock(): void
    {
        $lock = $this->mutex->acquire('job', 5);

        self::assertNotNull($lock);
        self::assertTrue($lock->refresh(10));
        self::assertTrue($lock->isHeld());
    }

    public function testExpiredLockCanBeTakenOverAndStaleOwnerCannotTouchIt(): void
    {
        if (!$this->supportsTtl()) {
            self::markTestSkipped('Store does not use TTL.');
        }

        $stale = $this->mutex->acquire('job', 1);
        self::assertNotNull($stale);

        $this->elapse(1100);

        self::assertFalse($this->mutex->isLocked('job'), 'expired lock is free');
        self::assertFalse($stale->refresh(), 'expired lock cannot be refreshed');

        $fresh = $this->mutex->acquire('job', 5);
        self::assertNotNull($fresh, 'expired lock can be taken over');

        // Hlavní rozdíl proti Laravel CacheEventMutex: původní vlastník nesmí smazat cizí zámek.
        self::assertFalse($stale->release());
        self::assertFalse($stale->refresh());
        self::assertFalse($stale->isHeld());
        self::assertTrue($fresh->isHeld());
        self::assertTrue($this->mutex->isLocked('job'));
    }

    public function testRefreshExtendsExpiry(): void
    {
        if (!$this->supportsTtl()) {
            self::markTestSkipped('Store does not use TTL.');
        }

        $lock = $this->mutex->acquire('job', 1);
        self::assertNotNull($lock);
        $this->elapse(600);
        self::assertTrue($lock->refresh(2));
        $this->elapse(600);

        self::assertTrue($lock->isHeld(), 'lock survives the original TTL after refresh');
        self::assertNull($this->mutex->acquire('job', 1));
    }

    public function testSynchronizedReleasesOnSuccessAndOnException(): void
    {
        $result = $this->mutex->synchronized('sync', fn () => $this->mutex->isLocked('sync') ? 'locked' : 'free', 5);
        self::assertSame('locked', $result);
        self::assertFalse($this->mutex->isLocked('sync'));

        try {
            $this->mutex->synchronized('sync', static fn () => throw new \DomainException('boom'), 5);
            self::fail('exception expected');
        } catch (\DomainException) {
        }
        self::assertFalse($this->mutex->isLocked('sync'));
    }

    public function testSynchronizedThrowsWhenLocked(): void
    {
        $this->mutex->acquire('sync', 5);

        $this->expectException(LockNotAcquiredException::class);
        $this->mutex->synchronized('sync', static fn () => null, 5);
    }

    public function testForceRelease(): void
    {
        $this->mutex->acquire('job', 5);
        $this->mutex->forceRelease('job');

        self::assertFalse($this->mutex->isLocked('job'));
    }

    public function testVeryLongKeyIsSupported(): void
    {
        $key = str_repeat('very-long-key-', 40);
        $lock = $this->mutex->acquire($key, 5);

        self::assertNotNull($lock);
        self::assertNull($this->mutex->acquire($key, 5));
        self::assertNotNull($this->mutex->acquire($key . 'x', 5), 'hashed keys must not collide');
    }

    public function testWaitGivesUpAfterTimeout(): void
    {
        $this->mutex->acquire('job', 30);

        $start = hrtime(true);
        self::assertNull($this->mutex->acquire('job', 5, waitSeconds: 1));
        self::assertGreaterThanOrEqual(0.9, (hrtime(true) - $start) / 1e9);
    }
}
