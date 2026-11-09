<?php

declare(strict_types=1);

namespace Emorfiq\Bridge\Laravel;

use Illuminate\Support\Facades\Date;
use Psr\Clock\ClockInterface;

/**
 * PSR-20 hodiny respektující Carbon::setTestNow() a app.timezone.
 */
final class LaravelClock implements ClockInterface
{
    public function now(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface(Date::now());
    }
}
