<?php

declare(strict_types=1);

namespace OrillaCart\Jobs\Infrastructure\Events;

use OrillaCart\Customers\Domain\Event\PersonErased;
use OrillaCart\Jobs\Application\FailedJobs;
use OrillaCart\Ordering\Domain\Event\OrderAnonymised;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Failed jobs that mention the email of a person erased, or of a customer erased from an order, are forgotten: their payloads and errors
 * may hold it, or a note the customer wrote.
 */
final readonly class ForgetErasedCustomers
{
    public function __construct(private FailedJobs $failed) {}

    #[AsEventListener]
    public function anonymised(OrderAnonymised $event): void
    {
        $this->failed->forgetMentioning($event->email);
    }

    /** A person erased who had no order, as a newsletter sign-up whose sync failed: their jobs go too. */
    #[AsEventListener]
    public function erased(PersonErased $event): void
    {
        $this->failed->forgetMentioning($event->email);
    }
}
