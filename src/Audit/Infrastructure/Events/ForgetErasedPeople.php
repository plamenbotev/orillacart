<?php

declare(strict_types=1);

namespace OrillaCart\Audit\Infrastructure\Events;

use OrillaCart\Audit\Application\ChangeLog;
use OrillaCart\Customers\Domain\Event\CustomerErased;
use OrillaCart\Customers\Domain\Event\PersonErased;
use OrillaCart\Ordering\Domain\Event\OrderAnonymised;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * The change log keeps what shop managers sent, which may be a customer's address or email: once a privacy
 * request erases them, from an order, an account or anywhere, the records about them keep no more than who
 * changed what, and when.
 */
final readonly class ForgetErasedPeople
{
    public function __construct(private ChangeLog $log) {}

    #[AsEventListener]
    public function anonymised(OrderAnonymised $event): void
    {
        $this->log->forget(["/orders/{$event->orderId}"], $event->email);
    }

    #[AsEventListener]
    public function customerErased(CustomerErased $event): void
    {
        $this->log->forget(["/customers/{$event->customerId}"]);
    }

    #[AsEventListener]
    public function personErased(PersonErased $event): void
    {
        $this->log->forget([], $event->email);
    }
}
