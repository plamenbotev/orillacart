<?php

declare(strict_types=1);

namespace OrillaCart\GiftCards\Application\Dto;

use OrillaCart\Shared\Domain\Money;
use OrillaCart\Shared\Domain\Text;

/**
 * How gift cards and store credit pay an order: each in turn takes what is left, the codes in the order they
 * were entered, store credit last.
 */
final readonly class GiftCardPlan
{
    /**
     * @param list<GiftCardUse>     $uses
     * @param array<string, Text> $refused why, by the code as entered
     * @param string                $storeCredit what the customer has as store credit, whether used or not
     */
    public function __construct(
        public array $uses = [],
        public array $refused = [],
        public string $storeCredit = '0',
    ) {}

    public function total(): Money
    {
        return array_reduce($this->uses, static fn(Money $sum, GiftCardUse $use): Money => $sum->add(Money::of($use->amount)), Money::zero());
    }
}
