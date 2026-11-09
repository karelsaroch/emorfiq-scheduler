<?php

declare(strict_types=1);

namespace Emorfiq\Scheduler;

use Emorfiq\Scheduler\Exception\DuplicateTaskException;
use Emorfiq\Scheduler\Exception\TaskNotFoundException;

final class TaskRegistry implements TaskRegistryInterface
{
    /** @var array<string, TaskDefinition> */
    private array $tasks = [];

    /** @param iterable<TaskProviderInterface> $providers */
    public function __construct(iterable $providers = [])
    {
        foreach ($providers as $provider) {
            foreach ($provider->tasks() as $task) {
                $this->add($task);
            }
        }
    }

    public function add(TaskDefinition $task): void
    {
        if (isset($this->tasks[$task->id])) {
            throw DuplicateTaskException::withId($task->id);
        }
        $this->tasks[$task->id] = $task;
    }

    public function get(string $id): TaskDefinition
    {
        return $this->tasks[$id] ?? throw TaskNotFoundException::withId($id);
    }

    public function has(string $id): bool
    {
        return isset($this->tasks[$id]);
    }

    public function all(): array
    {
        return array_values($this->tasks);
    }

    public function due(\DateTimeImmutable $at, string $environment): array
    {
        return array_values(array_filter(
            $this->tasks,
            static fn (TaskDefinition $t): bool => $t->runsIn($environment) && $t->isDue($at),
        ));
    }
}
