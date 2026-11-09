<?php

declare(strict_types=1);

namespace Emorfiq\Scheduler;

/**
 * Jak se má úloha chovat při souběhu. Vyhodnocuje ho výhradně náš
 * {@see TaskRunner} nad {@see \Emorfiq\Mutex\MutexInterface} – engine (Laravel/crunz)
 * o zámcích nic neví a jeho mutex se vůbec nepoužívá.
 */
final readonly class ExecutionPolicy
{
    /**
     * @param bool         $withoutOverlapping Nespouštět, pokud předchozí běh ještě neskončil.
     * @param bool         $onOneServer        Jeden výskyt (minuta) se v clusteru provede jen na jednom serveru.
     *                                         Zároveň mění rozsah $withoutOverlapping z "server" na "cluster".
     * @param positive-int $lockTtlSeconds     TTL běhového zámku; musí být delší než běžná doba úlohy,
     *                                         dlouhé úlohy volají {@see TaskContext::heartbeat()}.
     * @param int<0, max>  $lockWaitSeconds    Jak dlouho čekat na uvolnění zámku předchozího běhu (0 = hned přeskočit).
     * @param bool         $runInBackground    Engine nemá čekat na doběhnutí (úlohy ve stejné minutě běží paralelně).
     */
    public function __construct(
        public bool $withoutOverlapping = true,
        public bool $onOneServer = false,
        public int $lockTtlSeconds = 3600,
        public int $lockWaitSeconds = 0,
        public bool $runInBackground = true,
    ) {
        if ($lockTtlSeconds < 1) {
            throw new \InvalidArgumentException('lockTtlSeconds must be >= 1.');
        }
        if ($lockWaitSeconds < 0) {
            throw new \InvalidArgumentException('lockWaitSeconds must be >= 0.');
        }
    }

    public static function default(): self
    {
        return new self();
    }

    /** Max. jeden běh v celém clusteru. */
    public static function clusterExclusive(int $lockTtlSeconds = 3600): self
    {
        return new self(withoutOverlapping: true, onOneServer: true, lockTtlSeconds: $lockTtlSeconds);
    }

    /** Žádné zámky – úloha je idempotentní a souběh nevadí. */
    public static function allowOverlapping(): self
    {
        return new self(withoutOverlapping: false);
    }
}
