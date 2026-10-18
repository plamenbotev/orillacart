<?php

declare(strict_types=1);

namespace OrillaCart\Cart\Infrastructure\Events;

use OrillaCart\Cart\Domain\CartRepository;
use OrillaCart\Customers\Domain\Event\CustomerDeleted;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
final readonly class ForgetDeletedCustomers
{
    public function __construct(private CartRepository $carts) {}

    public function __invoke(CustomerDeleted $event): void
    {
        $this->carts->removeOfCustomer($event->customerId);
    }
}
