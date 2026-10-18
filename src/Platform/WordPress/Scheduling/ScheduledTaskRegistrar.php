<?php

declare(strict_types=1);

namespace OrillaCart\Platform\WordPress\Scheduling;

use OrillaCart\Platform\WordPress\Registrar;
use OrillaCart\Shared\WordPress\ScheduledTask;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\DependencyInjection\ServiceLocator;

/**
 * Hooks every scheduled task to its cron hook, and adds the plugin's recurrence; TaskSchedule puts the hooks on
 * the schedule.
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
        add_filter('cron_schedules', static function (mixed $schedules): mixed {
            if (\is_array($schedules)) {
                $schedules[ScheduledTask::EVERY_MINUTE] = ['interval' => MINUTE_IN_SECONDS, 'display' => __('Every minute', 'orillacart')];
            }

            return $schedules;
        });
        foreach (array_keys($this->tasks->getProvidedServices()) as $hook) {
            add_action($hook, function () use ($hook): void {
                $this->tasks->get($hook)->run();
            });
        }
    }
}
