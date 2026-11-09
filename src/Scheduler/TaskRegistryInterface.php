<?php

declare(strict_types=1);

namespace Emorfiq\Scheduler;

use Emorfiq\Scheduler\Exception\DuplicateTaskException;
use Emorfiq\Scheduler\Exception\TaskNotFoundException;

/**
 * Jediný zdroj pravdy o tom, jaké úlohy existují. Nezávislý na enginu.
 */
interface TaskRegistryInterface
{
    /** @throws DuplicateTaskException */
    public function add(TaskDefinition $task): void;

    /** @throws TaskNotFoundException */
    public function get(string $id): TaskDefinition;

    public function has(string $id): bool;

    /** @return list<TaskDefinition> */
    public function all(): array;

    /** @return list<TaskDefinition> Úlohy splatné v $at (enabled, prostředí, cron). */
    public function due(\DateTimeImmutable $at, string $environment): array;
}
