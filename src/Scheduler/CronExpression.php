<?php

declare(strict_types=1);

namespace Emorfiq\Scheduler;

use Emorfiq\Scheduler\Exception\InvalidCronExpressionException;

/**
 * Value object pro standardní 5polový crontab zápis:
 *
 *   ┌──────── minuta        0–59
 *   │ ┌────── hodina        0–23
 *   │ │ ┌──── den v měsíci  1–31
 *   │ │ │ ┌── měsíc         1–12 nebo JAN–DEC
 *   │ │ │ │ ┌ den v týdnu   0–7 nebo SUN–SAT (0 i 7 = neděle)
 *   * * * * *
 *
 * Podporuje: *, čísla, rozsahy (a-b), seznamy (a,b), kroky (*\/n, a-b/n, a/n)
 * a makra @yearly, @annually, @monthly, @weekly, @daily, @midnight, @hourly.
 * Den v měsíci a den v týdnu se kombinují jako OR, pokud jsou omezené oba (chování Vixie cron).
 *
 * Výpočet je vlastní – nezávisí na Laravelu ani dragonmantank/cron-expression.
 */
final class CronExpression implements \Stringable
{
    private const MACROS = [
        '@yearly' => '0 0 1 1 *',
        '@annually' => '0 0 1 1 *',
        '@monthly' => '0 0 1 * *',
        '@weekly' => '0 0 * * 0',
        '@daily' => '0 0 * * *',
        '@midnight' => '0 0 * * *',
        '@hourly' => '0 * * * *',
    ];

    private const NAMES = [
        3 => ['JAN' => 1, 'FEB' => 2, 'MAR' => 3, 'APR' => 4, 'MAY' => 5, 'JUN' => 6,
            'JUL' => 7, 'AUG' => 8, 'SEP' => 9, 'OCT' => 10, 'NOV' => 11, 'DEC' => 12],
        4 => ['SUN' => 0, 'MON' => 1, 'TUE' => 2, 'WED' => 3, 'THU' => 4, 'FRI' => 5, 'SAT' => 6],
    ];

    /** [min, max] pro jednotlivá pole */
    private const RANGES = [[0, 59], [0, 23], [1, 31], [1, 12], [0, 7]];

    /** Normalizovaný 5polový zápis (makra rozvinutá). */
    public readonly string $expression;

    /** @var array{0: array<int,true>, 1: array<int,true>, 2: array<int,true>, 3: array<int,true>, 4: array<int,true>} */
    private readonly array $allowed;
    private readonly bool $domRestricted;
    private readonly bool $dowRestricted;

    public function __construct(string $expression)
    {
        $normalized = trim((string) preg_replace('/\s+/', ' ', $expression));
        $normalized = self::MACROS[strtolower($normalized)] ?? $normalized;

        $fields = explode(' ', $normalized);
        if (count($fields) !== 5) {
            throw InvalidCronExpressionException::because($expression, 'expected exactly 5 fields');
        }

        $allowed = [];
        foreach ($fields as $index => $field) {
            $allowed[$index] = $this->parseField(strtoupper($field), $index, $expression);
        }
        // Neděle: 7 → 0
        if (isset($allowed[4][7])) {
            unset($allowed[4][7]);
            $allowed[4][0] = true;
        }

        $this->expression = $normalized;
        $this->allowed = $allowed;
        $this->domRestricted = !str_starts_with($fields[2], '*');
        $this->dowRestricted = !str_starts_with($fields[4], '*');
    }

    public static function fromString(string $expression): self
    {
        return new self($expression);
    }

    public static function isValid(string $expression): bool
    {
        try {
            new self($expression);

            return true;
        } catch (InvalidCronExpressionException) {
            return false;
        }
    }

    /**
     * Odpovídá daný okamžik (s přesností na minutu) výrazu? Čas se vyhodnocuje
     * v časové zóně předaného objektu.
     */
    public function matches(\DateTimeInterface $at): bool
    {
        if (!isset($this->allowed[0][(int) $at->format('i')], $this->allowed[1][(int) $at->format('G')], $this->allowed[3][(int) $at->format('n')])) {
            return false;
        }

        return $this->matchesDay($at);
    }

    /**
     * Nejbližší čas spuštění >= $from (zaokrouhleno nahoru na celou minutu, pokud $from není celá minuta;
     * s $inclusive = false striktně po $from).
     */
    public function nextRunAt(\DateTimeImmutable $from, bool $inclusive = false): \DateTimeImmutable
    {
        $t = $from->setTime((int) $from->format('G'), (int) $from->format('i'));
        if ($t < $from || !$inclusive) {
            $t = $t->modify('+1 minute');
        }

        // Max. ~5 let dopředu (např. 29. 2. kombinovaný s pondělím).
        for ($i = 0; $i < 100_000; ++$i) {
            if (!isset($this->allowed[3][(int) $t->format('n')])) {
                $t = $t->modify('first day of next month')->setTime(0, 0);
                continue;
            }
            if (!$this->matchesDay($t)) {
                $t = $t->modify('+1 day')->setTime(0, 0);
                continue;
            }
            if (!isset($this->allowed[1][(int) $t->format('G')])) {
                $t = $t->setTime((int) $t->format('G'), 0)->modify('+1 hour');
                continue;
            }
            if (!isset($this->allowed[0][(int) $t->format('i')])) {
                $t = $t->modify('+1 minute');
                continue;
            }

            return $t;
        }

        throw InvalidCronExpressionException::because($this->expression, 'no run date within search horizon');
    }

    public function __toString(): string
    {
        return $this->expression;
    }

    private function matchesDay(\DateTimeInterface $at): bool
    {
        $dom = isset($this->allowed[2][(int) $at->format('j')]);
        $dow = isset($this->allowed[4][(int) $at->format('w')]);

        if ($this->domRestricted && $this->dowRestricted) {
            return $dom || $dow;
        }

        return $dom && $dow;
    }

    /** @return array<int, true> */
    private function parseField(string $field, int $index, string $original): array
    {
        [$min, $max] = self::RANGES[$index];
        $values = [];

        foreach (explode(',', $field) as $part) {
            if (!preg_match('~^(\*|[0-9A-Z]+(?:-[0-9A-Z]+)?)(?:/(\d+))?$~', $part, $m)) {
                throw InvalidCronExpressionException::because($original, sprintf('invalid token "%s"', $part));
            }
            $step = isset($m[2]) ? (int) $m[2] : 1;
            if ($step < 1) {
                throw InvalidCronExpressionException::because($original, 'step must be >= 1');
            }

            if ($m[1] === '*') {
                [$from, $to] = [$min, $max];
            } elseif (str_contains($m[1], '-')) {
                [$a, $b] = explode('-', $m[1], 2);
                [$from, $to] = [$this->value($a, $index, $original), $this->value($b, $index, $original)];
                if ($from > $to) {
                    throw InvalidCronExpressionException::because($original, sprintf('invalid range "%s"', $m[1]));
                }
            } else {
                $from = $this->value($m[1], $index, $original);
                $to = isset($m[2]) ? $max : $from; // "a/n" = od a do maxima po n
            }

            for ($v = $from; $v <= $to; $v += $step) {
                $values[$v] = true;
            }
        }

        return $values;
    }

    private function value(string $token, int $index, string $original): int
    {
        $value = self::NAMES[$index][$token] ?? (ctype_digit($token) ? (int) $token : null);
        [$min, $max] = self::RANGES[$index];

        if ($value === null || $value < $min || $value > $max) {
            throw InvalidCronExpressionException::because($original, sprintf('value "%s" out of range %d-%d', $token, $min, $max));
        }

        return $value;
    }
}
