<?php

declare(strict_types=1);

namespace OrillaCart\Audit\Infrastructure\Privacy;

use OrillaCart\Audit\Application\ChangeLog;
use OrillaCart\Audit\Application\ShopProblems;
use OrillaCart\Shared\Privacy\ErasesPersonalData;

/**
 * The change log keeps what shop managers sent, which may be a customer's address or email: once a privacy
 * request erases them, from an order, an account or anywhere, the records about them keep no more than who
 * changed what, and when. The problems the shop logged about them (an email that did not go, a VAT number the
 * registry would not check) go altogether.
 */
final readonly class ForgetErasedPeople implements ErasesPersonalData
{
    public function __construct(private ChangeLog $log, private ShopProblems $problems) {}

    public function erasedFromOrder(int $orderId, string $email): void
    {
        $this->log->forget(["/orders/{$orderId}"], $email);
        $this->problems->forgetPerson($email);
    }

    public function erasedCustomer(int $customerId): void
    {
        $this->log->forget(["/customers/{$customerId}"]);
    }

    public function erasedPerson(string $email): void
    {
        $this->log->forget([], $email);
        $this->problems->forgetPerson($email);
    }
}
