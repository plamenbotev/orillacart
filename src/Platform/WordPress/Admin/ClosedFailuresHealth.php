<?php

declare(strict_types=1);

namespace OrillaCart\Platform\WordPress\Admin;

use OrillaCart\Shared\Extension\ClosedFailures;
use OrillaCart\Shared\WordPress\HealthCheck;
use OrillaCart\Shared\WordPress\HealthStatus;
use Psr\Clock\ClockInterface;

/**
 * Whether an add-on the shop cannot do without failed in the last hour: while it does, shoppers are refused (no
 * cart can be ordered, no address passes its check). Critical, so Home puts it first among what needs the shop
 * manager.
 */
final readonly class ClosedFailuresHealth implements HealthCheck
{
    private const string WITHIN = '-1 hour';

    public function __construct(
        private ClosedFailures $failures,
        private ClockInterface $clock,
    ) {}

    public static function id(): string
    {
        return 'closed_failures';
    }

    public static function label(): string
    {
        return __('Add-ons the shop cannot do without', 'orillacart');
    }

    public function check(): HealthStatus
    {
        $failed = $this->failures->since($this->clock->now()->modify(self::WITHIN));
        if ($failed === []) {
            return HealthStatus::good(__('No add-on the shop cannot do without failed in the last hour', 'orillacart'));
        }

        return HealthStatus::critical(
            \sprintf(
                /* translators: %d: how many add-on parts */
                _n('%d add-on the shop cannot do without failed in the last hour', '%d add-ons the shop cannot do without failed in the last hour', \count($failed), 'orillacart'),
                \count($failed),
            ),
            __('While one fails, shoppers are refused until it answers again.', 'orillacart') . ' ' . implode(' ', array_column($failed, 'what')),
            admin_url('admin.php?page=orillacart-extensions'),
            __('Open Extensions', 'orillacart'),
        );
    }

    public function info(): array
    {
        return ['closed_failures' => ['label' => __('Add-ons the shop cannot do without that failed in the last hour', 'orillacart'), 'value' => (string) \count($this->failures->since($this->clock->now()->modify(self::WITHIN)))]];
    }
}
