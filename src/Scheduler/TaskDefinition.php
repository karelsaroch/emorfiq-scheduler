<?php

declare(strict_types=1);

namespace Emorfiq\Scheduler;

/**
 * Immutable popis plánované úlohy – "co", "kdy" a "jak".
 *
 * Definice jsou čistá data (bez closure), aby je šlo znovu sestavit v libovolném
 * procesu: engine spouští jen `scheduler:run-task <id>` a runner si definici
 * dohledá v registru podle ID.
 */
final readonly class TaskDefinition
{
    /**
     * @param non-empty-string                     $id           Stabilní unikátní ID ([a-z0-9][a-z0-9._-]*), slouží i jako klíč zámku.
     * @param class-string<TaskHandlerInterface>   $handler      Třída handleru; instancuje se přes DI kontejner.
     * @param array<string, scalar|array|null>     $parameters   Statické parametry předané handleru přes {@see TaskContext}.
     * @param \DateTimeZone|null                   $timezone     Zóna, ve které se vyhodnocuje cron (null = výchozí zóna aplikace).
     * @param list<non-empty-string>               $environments Prostředí, kde se úloha plánuje ([] = všude).
     */
    public function __construct(
        public string $id,
        public CronExpression $cron,
        public string $handler,
        public array $parameters = [],
        public ExecutionPolicy $policy = new ExecutionPolicy(),
        public ?\DateTimeZone $timezone = null,
        public array $environments = [],
        public ?string $description = null,
        public bool $enabled = true,
    ) {
        if (!preg_match('/^[a-z0-9][a-z0-9._-]{0,99}$/', $id)) {
            throw new \InvalidArgumentException(sprintf('Invalid task id "%s" (allowed: [a-z0-9][a-z0-9._-]{0,99}).', $id));
        }
        if (!is_a($handler, TaskHandlerInterface::class, true)) {
            throw new \InvalidArgumentException(sprintf('Handler "%s" of task "%s" must implement %s.', $handler, $id, TaskHandlerInterface::class));
        }
    }

    /**
     * Pohodlná továrna: TaskDefinition::cron('report.daily', '0 6 * * *', DailyReportHandler::class)
     *
     * @param class-string<TaskHandlerInterface> $handler
     */
    public static function cron(string $id, string|CronExpression $expression, string $handler): self
    {
        return new self($id, $expression instanceof CronExpression ? $expression : new CronExpression($expression), $handler);
    }

    /** @param array<string, scalar|array|null> $parameters */
    public function withParameters(array $parameters): self
    {
        return $this->with(['parameters' => $parameters]);
    }

    public function withPolicy(ExecutionPolicy $policy): self
    {
        return $this->with(['policy' => $policy]);
    }

    public function withTimezone(\DateTimeZone|string $timezone): self
    {
        return $this->with(['timezone' => is_string($timezone) ? new \DateTimeZone($timezone) : $timezone]);
    }

    /** @param non-empty-string ...$environments */
    public function onlyIn(string ...$environments): self
    {
        return $this->with(['environments' => array_values($environments)]);
    }

    public function withDescription(string $description): self
    {
        return $this->with(['description' => $description]);
    }

    public function disabled(): self
    {
        return $this->with(['enabled' => false]);
    }

    public function runsIn(string $environment): bool
    {
        return $this->environments === [] || in_array($environment, $this->environments, true);
    }

    /** Je úloha splatná v daný okamžik (v zóně úlohy)? */
    public function isDue(\DateTimeImmutable $at): bool
    {
        return $this->enabled && $this->cron->matches($this->localize($at));
    }

    public function nextRunAt(\DateTimeImmutable $from): \DateTimeImmutable
    {
        return $this->cron->nextRunAt($this->localize($from));
    }

    private function localize(\DateTimeImmutable $at): \DateTimeImmutable
    {
        return $this->timezone !== null ? $at->setTimezone($this->timezone) : $at;
    }

    /** @param array<string, mixed> $changes */
    private function with(array $changes): self
    {
        return new self(...[...get_object_vars($this), ...$changes]);
    }
}
