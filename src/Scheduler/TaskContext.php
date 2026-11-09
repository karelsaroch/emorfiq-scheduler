<?php

declare(strict_types=1);

namespace Emorfiq\Scheduler;

use Emorfiq\Mutex\LockInterface;

/**
 * Runtime kontext jednoho běhu úlohy předaný handleru.
 */
final class TaskContext
{
    /**
     * @param \DateTimeImmutable $scheduledAt Minuta, pro kterou engine úlohu naplánoval (ne skutečný start).
     * @param non-empty-string   $runId       Unikátní ID běhu (korelace v logu).
     */
    public function __construct(
        public readonly TaskDefinition $task,
        public readonly \DateTimeImmutable $scheduledAt,
        public readonly string $runId,
        private readonly ?LockInterface $lock = null,
    ) {
    }

    public function parameter(string $name, mixed $default = null): mixed
    {
        return $this->task->parameters[$name] ?? $default;
    }

    /**
     * Prodlouží běhový zámek. Dlouhé úlohy ho volají průběžně (např. po každé dávce).
     *
     * @param positive-int|null $ttlSeconds
     *
     * @return bool false = zámek byl ztracen → úloha by měla bezpečně skončit.
     */
    public function heartbeat(?int $ttlSeconds = null): bool
    {
        return $this->lock?->refresh($ttlSeconds) ?? true;
    }
}
