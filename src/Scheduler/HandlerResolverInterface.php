<?php

declare(strict_types=1);

namespace Emorfiq\Scheduler;

interface HandlerResolverInterface
{
    /** @param class-string<TaskHandlerInterface> $handlerClass */
    public function resolve(string $handlerClass): TaskHandlerInterface;
}
