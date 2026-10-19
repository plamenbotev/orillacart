<?php

declare(strict_types=1);

namespace OrillaCart\Platform\WordPress\Admin;

use OrillaCart\Platform\Doctrine\DatabaseMigrator;
use OrillaCart\Platform\Lifecycle\PluginLifecycle;
use OrillaCart\Platform\PluginContext;
use OrillaCart\Shared\WordPress\HealthCheck;
use OrillaCart\Shared\WordPress\HealthStatus;

/**
 * Whether the shop's tables are at this version's schema: an update whose migration failed, or did not run yet,
 * leaves the shop on tables it does not expect.
 */
final readonly class DatabaseHealth implements HealthCheck
{
    public function __construct(
        private DatabaseMigrator $migrator,
        private PluginContext $context,
    ) {}

    public static function id(): string
    {
        return 'database';
    }

    public static function label(): string
    {
        return __('The shop’s database tables', 'orillacart');
    }

    public function check(): HealthStatus
    {
        $failed = get_option(PluginLifecycle::UPGRADE_FAILED_OPTION);
        $pending = $this->migrator->pending();
        if (\is_array($failed) || $pending !== []) {
            return HealthStatus::critical(
                __('The shop’s tables are not up to date', 'orillacart'),
                \sprintf(
                    /* translators: 1: how many changes, 2: the error, if any */
                    __('%1$d changes to the tables have not run. %2$s Run "wp orillacart migrate" to apply them and see what fails.', 'orillacart'),
                    \count($pending),
                    \is_array($failed) && \is_string($failed['message'] ?? null) ? $failed['message'] : '',
                ),
            );
        }

        return HealthStatus::good(__('The shop’s tables are up to date', 'orillacart'));
    }

    public function info(): array
    {
        $installed = get_option(PluginLifecycle::VERSION_OPTION);
        $pending = $this->migrator->pending();

        return [
            'version' => ['label' => __('Version', 'orillacart'), 'value' => $this->context->version],
            'schema' => ['label' => __('Tables set up for', 'orillacart'), 'value' => \is_string($installed) ? $installed : '—'],
            'pending' => ['label' => __('Changes to the tables not run', 'orillacart'), 'value' => $pending === [] ? '0' : implode(', ', $pending)],
        ];
    }
}
