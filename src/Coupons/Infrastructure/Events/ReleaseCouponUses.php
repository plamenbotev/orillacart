<?php

declare(strict_types=1);

namespace OrillaCart\Coupons\Infrastructure\Events;

use OrillaCart\Coupons\Application\Discounts;
use OrillaCart\Ordering\Domain\Event\OrderDeleted;
use OrillaCart\Ordering\Domain\Event\OrderStatusChanged;
use OrillaCart\Ordering\Domain\OrderStatus;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * An order that is cancelled, fails or is deleted gives back its coupon uses, so the customer can use the code
 * again. A refunded order keeps them: it was used.
 */
final readonly class ReleaseCouponUses
{
    public function __construct(private Discounts $discounts) {}

    #[AsEventListener]
    public function statusChanged(OrderStatusChanged $event): void
    {
        $closed = [OrderStatus::Cancelled, OrderStatus::Failed];
        if (\in_array($event->status, $closed, true)) {
            $this->discounts->release($event->orderId);
        } elseif (\in_array($event->previous, $closed, true)) {
            // Opened again, paid after all.
            $this->discounts->reclaim($event->orderId);
        }
    }

    #[AsEventListener]
    public function deleted(OrderDeleted $event): void
    {
        $this->discounts->release($event->orderId);
    }
}
