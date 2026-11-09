<?php

declare(strict_types=1);

namespace Emorfiq\Tests\Support;

use Psr\Clock\ClockInterface;

final class FakeClock implements ClockInterface
{
    public function __construct(private \DateTimeImmutable $now = new \DateTimeImmutable('2026-11-09 10:00:00'))
    {
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }

    public function set(\DateTimeImmutable|string $now): void
    {
        $this->now = is_string($now) ? new \DateTimeImmutable($now) : $now;
    }

    public function advanceMs(int $ms): void
    {
        $this->now = $this->now->modify(sprintf('+%d milliseconds', $ms));
    }
}
