<?php

declare(strict_types=1);

namespace OrillaCart\Jobs\Infrastructure;

use OrillaCart\Jobs\Application\JobHandler;
use OrillaCart\Jobs\Application\JobHandlers;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\DependencyInjection\ServiceLocator;

/**
 * The handlers the container found, by their job type; each is built only when a job of its type runs.
 */
final readonly class LocatedJobHandlers implements JobHandlers
{
    /**
     * @param ServiceLocator<JobHandler> $handlers by type
     */
    public function __construct(
        #[AutowireLocator(JobHandler::TAG, defaultIndexMethod: 'type')]
        private ServiceLocator $handlers,
    ) {}

    public function get(string $type): JobHandler
    {
        if (!$this->handlers->has($type)) {
            throw new \LogicException(\sprintf('No handler does jobs of type "%s".', $type));
        }

        return $this->handlers->get($type);
    }

    public function types(): array
    {
        $types = array_map('strval', array_keys($this->handlers->getProvidedServices()));
        sort($types);

        return $types;
    }

    public function queues(): array
    {
        $queues = [];
        foreach ($this->handlers->getProvidedServices() as $class) {
            if (\is_string($class) && is_a($class, JobHandler::class, true)) {
                $queues[$class::queue()] = true;
            }
        }
        $queues = array_map('strval', array_keys($queues));
        sort($queues);

        return $queues;
    }
}
