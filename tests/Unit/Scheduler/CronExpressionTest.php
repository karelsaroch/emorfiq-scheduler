<?php

declare(strict_types=1);

namespace Emorfiq\Tests\Unit\Scheduler;

use Emorfiq\Scheduler\CronExpression;
use Emorfiq\Scheduler\Exception\InvalidCronExpressionException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CronExpressionTest extends TestCase
{
    /** @return iterable<string, array{string, string, bool}> */
    public static function matchCases(): iterable
    {
        // 2026-11-09 = pondělí
        yield 'every minute' => ['* * * * *', '2026-11-09 13:37', true];
        yield 'step match' => ['*/15 9-17 * * MON-FRI', '2026-11-09 09:30', true];
        yield 'step no match' => ['*/15 9-17 * * MON-FRI', '2026-11-09 09:31', false];
        yield 'out of hour range' => ['*/15 9-17 * * MON-FRI', '2026-11-09 18:00', false];
        yield 'weekend' => ['*/15 9-17 * * MON-FRI', '2026-11-08 09:30', false];
        yield 'list' => ['0 8,12,16 * * *', '2026-11-09 12:00', true];
        yield 'range with step' => ['0-30/10 * * * *', '2026-11-09 10:20', true];
        yield 'range with step no' => ['0-30/10 * * * *', '2026-11-09 10:40', false];
        yield 'value with step' => ['5/20 * * * *', '2026-11-09 10:45', true];
        yield 'month name' => ['0 0 1 JAN *', '2026-01-01 00:00', true];
        yield 'sunday as 7' => ['0 0 * * 7', '2026-11-08 00:00', true];
        yield 'sunday as 0' => ['0 0 * * 0', '2026-11-08 00:00', true];
        yield 'dom OR dow (dom)' => ['0 0 13 * FRI', '2026-11-13 00:00', true];
        yield 'dom OR dow (dow)' => ['0 0 13 * FRI', '2026-11-06 00:00', true];
        yield 'dom OR dow (neither)' => ['0 0 13 * FRI', '2026-11-12 00:00', false];
        yield 'dom only' => ['0 0 13 * *', '2026-11-06 00:00', false];
        yield 'macro daily' => ['@daily', '2026-11-09 00:00', true];
        yield 'macro hourly' => ['@hourly', '2026-11-09 10:01', false];
        yield 'lowercase names' => ['0 6 * * mon', '2026-11-09 06:00', true];
    }

    #[DataProvider('matchCases')]
    public function testMatches(string $expression, string $at, bool $expected): void
    {
        self::assertSame($expected, (new CronExpression($expression))->matches(new \DateTimeImmutable($at)));
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function nextRunCases(): iterable
    {
        yield 'next minute' => ['* * * * *', '2026-11-09 10:00:00', '2026-11-09 10:01'];
        yield 'mid-minute rounds up' => ['* * * * *', '2026-11-09 10:00:30', '2026-11-09 10:01'];
        yield 'over weekend' => ['*/15 9-17 * * MON-FRI', '2026-11-13 17:50', '2026-11-16 09:00'];
        yield 'next day' => ['5 3 * * *', '2026-11-09 03:05', '2026-11-10 03:05'];
        yield 'next month' => ['0 0 1 * *', '2026-11-09 10:00', '2026-12-01 00:00'];
        yield 'leap day' => ['0 0 29 2 *', '2026-03-01 00:00', '2028-02-29 00:00'];
        yield 'year rollover' => ['0 0 1 1 *', '2026-11-09 10:00', '2027-01-01 00:00'];
    }

    #[DataProvider('nextRunCases')]
    public function testNextRunAt(string $expression, string $from, string $expected): void
    {
        $next = (new CronExpression($expression))->nextRunAt(new \DateTimeImmutable($from));

        self::assertSame($expected, $next->format('Y-m-d H:i'));
    }

    public function testNextRunAtInclusive(): void
    {
        $next = (new CronExpression('0 10 * * *'))->nextRunAt(new \DateTimeImmutable('2026-11-09 10:00:00'), inclusive: true);

        self::assertSame('2026-11-09 10:00', $next->format('Y-m-d H:i'));
    }

    public function testNormalizesMacrosAndWhitespace(): void
    {
        self::assertSame('0 0 * * *', (string) new CronExpression('@midnight'));
        self::assertSame('*/5 * * * *', (new CronExpression("  */5   *\t* * * "))->expression);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidCases(): iterable
    {
        yield 'too few fields' => ['* * * *'];
        yield 'too many fields' => ['* * * * * *'];
        yield 'minute 60' => ['60 * * * *'];
        yield 'hour 24' => ['0 24 * * *'];
        yield 'dom 0' => ['0 0 0 * *'];
        yield 'month 13' => ['0 0 1 13 *'];
        yield 'dow 8' => ['0 0 * * 8'];
        yield 'reversed range' => ['5-1 * * * *'];
        yield 'zero step' => ['*/0 * * * *'];
        yield 'garbage' => ['a * * * *'];
        yield 'unknown name' => ['0 0 * * FOO'];
        yield 'unsupported L' => ['0 0 L * *'];
        yield 'empty' => [''];
    }

    #[DataProvider('invalidCases')]
    public function testRejectsInvalid(string $expression): void
    {
        self::assertFalse(CronExpression::isValid($expression));
        $this->expectException(InvalidCronExpressionException::class);
        new CronExpression($expression);
    }
}
