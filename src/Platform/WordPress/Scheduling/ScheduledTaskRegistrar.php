<?php

declare(strict_types=1);

namespace OrillaCart\Platform\WordPress\Scheduling;

use OrillaCart\Platform\WordPress\Registrar;
use OrillaCart\Shared\WordPress\ScheduledTask;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\DependencyInjection\ServiceLocator;

/**
 * Hooks every scheduled task to its cron hook; TaskSchedule puts the hooks on the schedule.
 */
final readonly class ScheduledTaskRegistrar implements Registrar
{
    /**
     * @param ServiceLocator<ScheduledTask> $tasks by hook
     */
    public function __construct(
        #[AutowireLocator(ScheduledTask::TAG, defaultIndexMethod: 'hook')]
        private ServiceLocator $tasks,
    ) {}

    public function register(): void
    {
        foreach (array_keys($this->tasks->getProvidedServices()) as $hook) {
            add_action($hook, function () use ($hook): void {
                $this->tasks->get($hook)->run();
            });
        }
    }
}
