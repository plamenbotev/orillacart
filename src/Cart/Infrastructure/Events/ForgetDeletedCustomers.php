<?php

declare(strict_types=1);

namespace OrillaCart\Cart\Infrastructure\Events;

use OrillaCart\Cart\Domain\CartRepository;
use OrillaCart\Customers\Domain\Event\CustomerDeleted;
use OrillaCart\Customers\Domain\Event\CustomerErased;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

final readonly class ForgetDeletedCustomers
{
    public function __construct(private CartRepository $carts) {}

    #[AsEventListener]
    public function __invoke(CustomerDeleted $event): void
    {
        $this->carts->removeOfCustomer($event->customerId);
    }

    /** Erased, as a privacy request asked: their cart goes too. */
    #[AsEventListener]
    public function erased(CustomerErased $event): void
    {
        $this->carts->removeOfCustomer($event->customerId);
    }
}
