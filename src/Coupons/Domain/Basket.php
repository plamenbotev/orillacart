<?php

declare(strict_types=1);

namespace OrillaCart\Coupons\Domain;

use OrillaCart\Shared\Domain\Money;

/**
 * What coupons are checked against: the items, who orders them, and where they go.
 */
final readonly class Basket
{
    /**
     * @param list<BasketLine> $lines
     * @param string|null      $country the shipping country, when known; country rules wait for it
     * @param bool             $firstOrder the customer never ordered before
     */
    public function __construct(
        public array $lines,
        public string $currency,
        public ?string $country = null,
        public ?int $customerId = null,
        public ?string $email = null,
        public bool $firstOrder = true,
    ) {}

    public function subtotal(): Money
    {
        return array_reduce($this->lines, static fn(Money $sum, BasketLine $line): Money => $sum->add($line->total), Money::zero());
    }
}
