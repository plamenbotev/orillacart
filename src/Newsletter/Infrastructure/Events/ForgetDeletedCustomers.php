<?php

declare(strict_types=1);

namespace OrillaCart\Newsletter\Infrastructure\Events;

use OrillaCart\Customers\Domain\Event\CustomerDeleted;
use OrillaCart\Newsletter\Domain\SubscriberRepository;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * A sign-up stays with its email when the account it was made from is deleted: the person asked for the
 * newsletter, not the account. It no longer names the account.
 */
#[AsEventListener]
final readonly class ForgetDeletedCustomers
{
    public function __construct(private SubscriberRepository $subscribers) {}

    public function __invoke(CustomerDeleted $event): void
    {
        $this->subscribers->forgetCustomer($event->customerId);
    }
}
