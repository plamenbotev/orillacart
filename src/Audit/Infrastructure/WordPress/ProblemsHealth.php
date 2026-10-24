<?php

declare(strict_types=1);

namespace OrillaCart\Audit\Infrastructure\WordPress;

use OrillaCart\Audit\Application\ShopProblems;
use OrillaCart\Shared\WordPress\HealthCheck;
use OrillaCart\Shared\WordPress\HealthStatus;

/** Whether the shop logged problems in the last day: payments, refunds, emails, couriers, add-ons. */
final readonly class ProblemsHealth implements HealthCheck
{
    public function __construct(private ShopProblems $problems) {}

    public static function id(): string
    {
        return 'problems';
    }

    public static function label(): string
    {
        return __('Problems the shop logged', 'orillacart');
    }

    public function check(): HealthStatus
    {
        $lately = $this->problems->lately();
        if ($lately === 0) {
            return HealthStatus::good(__('The shop logged no problems in the last day', 'orillacart'));
        }

        return HealthStatus::recommended(
            \sprintf(
                /* translators: %d: how many problems */
                _n('The shop logged %d problem in the last day', 'The shop logged %d problems in the last day', $lately, 'orillacart'),
                $lately,
            ),
            __('Such as a payment or refund it could not record, an email that did not go, or a courier or add-on that failed. Each says what to do.', 'orillacart'),
            admin_url('admin.php?page=orillacart-status'),
            __('Open Status', 'orillacart'),
        );
    }

    public function info(): array
    {
        return ['problems' => ['label' => __('Problems in the last day', 'orillacart'), 'value' => (string) $this->problems->lately()]];
    }
}
