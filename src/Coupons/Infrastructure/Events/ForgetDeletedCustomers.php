<?php

declare(strict_types=1);

namespace OrillaCart\Coupons\Infrastructure\Events;

use OrillaCart\Coupons\Domain\RedemptionRepository;
use OrillaCart\Customers\Domain\Event\CustomerDeleted;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * A deleted customer's uses of coupons stay counted by their email, so limits per customer still hold for whoever
 * orders with it; they no longer name the account.
 */
#[AsEventListener]
final readonly class ForgetDeletedCustomers
{
    public function __construct(private RedemptionRepository $redemptions) {}

    public function __invoke(CustomerDeleted $event): void
    {
        $this->redemptions->forgetCustomer($event->customerId);
    }
}
