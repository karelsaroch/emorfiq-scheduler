<?php

declare(strict_types=1);

namespace Emorfiq\Scheduler;

use Psr\Container\ContainerInterface;

/**
 * Resolver nad PSR-11 kontejnerem (Laravel Container PSR-11 implementuje a umí autowiring).
 */
final class ContainerHandlerResolver implements HandlerResolverInterface
{
    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public function resolve(string $handlerClass): TaskHandlerInterface
    {
        $handler = $this->container->get($handlerClass);
        if (!$handler instanceof TaskHandlerInterface) {
            throw new \UnexpectedValueException(sprintf('Service "%s" is not a %s.', $handlerClass, TaskHandlerInterface::class));
        }

        return $handler;
    }
}
