<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Infrastructure\Events;

use OrillaCart\Customers\Domain\Event\CustomerDeleted;
use OrillaCart\Ordering\Application\OrderService;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
final readonly class ForgetDeletedCustomers
{
    public function __construct(private OrderService $orders) {}

    public function __invoke(CustomerDeleted $event): void
    {
        $this->orders->forgetCustomer($event->customerId);
    }
}
