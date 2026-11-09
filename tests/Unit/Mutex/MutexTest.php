<?php

declare(strict_types=1);

namespace Emorfiq\Tests\Unit\Mutex;

use Emorfiq\Mutex\Mutex;
use Emorfiq\Mutex\Store\InMemoryLockStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MutexTest extends TestCase
{
    /** @return iterable<string, array{string, int, int}> */
    public static function invalidArguments(): iterable
    {
        yield 'empty key' => ['', 10, 0];
        yield 'zero ttl' => ['k', 0, 0];
        yield 'negative wait' => ['k', 10, -1];
    }

    #[DataProvider('invalidArguments')]
    public function testRejectsInvalidArguments(string $key, int $ttl, int $wait): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Mutex(new InMemoryLockStore()))->acquire($key, $ttl, $wait);
    }

    public function testPrefixSeparatesNamespaces(): void
    {
        $store = new InMemoryLockStore();

        self::assertNotNull((new Mutex($store, 'app-a:'))->acquire('job', 10));
        self::assertNotNull((new Mutex($store, 'app-b:'))->acquire('job', 10));
        self::assertNull((new Mutex($store, 'app-a:'))->acquire('job', 10));
    }

    public function testTokensAreUnique(): void
    {
        $mutex = new Mutex(new InMemoryLockStore());
        $a = $mutex->acquire('a', 10);
        $b = $mutex->acquire('b', 10);

        self::assertNotNull($a);
        self::assertNotNull($b);
        self::assertNotSame($a->token(), $b->token());
        self::assertSame(32, strlen($a->token()));
    }
}
