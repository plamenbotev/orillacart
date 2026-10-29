<?php

declare(strict_types=1);

namespace OrillaCart\Platform\WordPress\Scheduling;

use OrillaCart\Shared\Container\LocatedServices;
use OrillaCart\Shared\Lifecycle\LifecycleListener;
use OrillaCart\Shared\WordPress\ScheduledTask;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\DependencyInjection\ServiceLocator;

/**
 * Puts every scheduled task on the WP-Cron schedule while the plugin is active, and takes it off after.
 */
final readonly class TaskSchedule implements LifecycleListener
{
    /**
     * @param ServiceLocator<ScheduledTask> $tasks by hook
     */
    public function __construct(
        #[AutowireLocator(ScheduledTask::TAG, defaultIndexMethod: 'hook')]
        private ServiceLocator $tasks,
    ) {}

    public function activate(): void
    {
        foreach (LocatedServices::classes($this->tasks) as $hook => $class) {
            if (!is_subclass_of($class, ScheduledTask::class)) {
                throw new \LogicException(\sprintf('%s is not a scheduled task.', $class));
            }
            if (wp_next_scheduled($hook) === false) {
                wp_schedule_event(time() + 60, $class::recurrence(), $hook);
            }
        }
    }

    public function upgrade(?string $fromVersion): void
    {
        $this->activate();
    }

    public function deactivate(): void
    {
        foreach (array_keys($this->tasks->getProvidedServices()) as $hook) {
            wp_clear_scheduled_hook($hook);
        }
    }

    public function uninstall(): void
    {
        $this->deactivate();
    }

    /** @return list<string> the cron hooks of the plugin's tasks */
    public function hooks(): array
    {
        return array_keys($this->tasks->getProvidedServices());
    }
}
