<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Application;

use OrillaCart\Payment\Application\Dto\StoreCreditGiven;
use OrillaCart\Shared\Domain\Money;

/**
 * A refund given as something to spend in the shop rather than back to how the customer paid. Gift cards, above
 * Payment, give it.
 */
interface StoreCredit
{
    /**
     * The customer's store credit, or for a guest a new gift card, to send them once the refund is saved. Run it
     * in the transaction that records the refund.
     */
    public function refundAsCredit(int $orderId, ?int $customerId, string $email, string $name, Money $amount, string $currency, ?int $userId, ?string $locale = null): StoreCreditGiven;
}
