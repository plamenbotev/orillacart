<?php

declare(strict_types=1);

namespace OrillaCart\Jobs\Infrastructure\Privacy;

use OrillaCart\Jobs\Application\FailedJobs;
use OrillaCart\Shared\Privacy\ErasesPersonalData;

/**
 * Failed jobs that mention the email of a person erased, or of a customer erased from an order, are forgotten: their
 * payloads and errors may hold it, or a note the customer wrote.
 */
final readonly class ForgetErasedCustomers implements ErasesPersonalData
{
    public function __construct(private FailedJobs $failed) {}

    public function erasedFromOrder(int $orderId, string $email): void
    {
        $this->failed->forgetMentioning($email);
    }

    /** A person erased who had no order, as a newsletter sign-up whose sync failed: their jobs go too. */
    public function erasedPerson(string $email): void
    {
        $this->failed->forgetMentioning($email);
    }

    /** A customer's jobs carry their email, which erasedPerson takes. */
    public function erasedCustomer(int $customerId): void {}
}
