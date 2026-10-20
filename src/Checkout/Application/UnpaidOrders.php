<?php

declare(strict_types=1);

namespace OrillaCart\Checkout\Application;

use OrillaCart\Ordering\Application\OrderService;
use OrillaCart\Ordering\Domain\OrderNotFound;
use OrillaCart\Payment\Application\PaymentOptions;
use OrillaCart\Settings\Application\SettingsService;
use Psr\Clock\ClockInterface;

/**
 * Cancels the orders on hold for a payment made outside the shop (bank transfer, cheque) that stayed unpaid the
 * shop's number of days, so their stock sells again; never when the shop sets none. Cash on delivery is paid when
 * the order arrives, and waits as long as it takes.
 */
final readonly class UnpaidOrders
{
    public function __construct(
        private OrderService $orders,
        private PaymentOptions $payments,
        private SettingsService $settings,
        private ClockInterface $clock,
    ) {}

    /** @return int how many were cancelled */
    public function expire(): int
    {
        $days = $this->settings->current()->unpaidHoldDays;
        if ($days === null) {
            return 0;
        }
        $cancelled = 0;
        foreach ($this->orders->unpaidOnHold(new \DateTimeImmutable('@0'), $this->clock->now()->sub(new \DateInterval("P{$days}D")), $this->payments->paidOffline()) as $id) {
            try {
                $cancelled += $this->orders->expireUnpaid($id, $days) ? 1 : 0;
            } catch (OrderNotFound) {
                // Deleted since.
            }
        }

        return $cancelled;
    }
}
