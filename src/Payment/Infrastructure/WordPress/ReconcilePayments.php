<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Infrastructure\WordPress;

use OrillaCart\Payment\Application\PaymentOutcomes;
use OrillaCart\Payment\Application\Refunds;
use OrillaCart\Shared\WordPress\ScheduledTask;

/**
 * Every hour: asks the providers how payments and refunds stand that the shop heard nothing final of, so they
 * settle even when a notification never came.
 */
final readonly class ReconcilePayments implements ScheduledTask
{
    public function __construct(
        private PaymentOutcomes $payments,
        private Refunds $refunds,
    ) {}

    public static function hook(): string
    {
        return 'orillacart_reconcile_payments';
    }

    public static function recurrence(): string
    {
        return 'hourly';
    }

    public function run(): void
    {
        $this->payments->reconcile();
        $this->refunds->reconcile();
    }
}
