<?php

declare(strict_types=1);

namespace OrillaCart\Coupons\Infrastructure\Events;

use OrillaCart\Coupons\Domain\RedemptionRepository;
use OrillaCart\Ordering\Domain\Event\OrderAnonymised;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * A coupon use keeps the order and the amount, not the email of a customer erased from it.
 */
final readonly class ForgetErasedCustomers
{
    public function __construct(private RedemptionRepository $redemptions) {}

    #[AsEventListener]
    public function anonymised(OrderAnonymised $event): void
    {
        foreach ($this->redemptions->ofOrder($event->orderId) as $redemption) {
            $redemption->forgetEmail();
            $this->redemptions->save($redemption);
        }
    }
}
