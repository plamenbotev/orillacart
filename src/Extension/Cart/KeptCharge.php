<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Cart;

/**
 * A price of its own a cart line type keeps in the cart, with no product: a deposit, a recycling fee, insurance.
 */
final readonly class KeptCharge
{
    /**
     * @param string   $label      what the shopper reads on the line: "Bottle deposit"; up to 100 characters, and
     *                             a type's charges are told apart by it
     * @param string   $amount     so much each, in the shop's currency as its prices are (before tax, or with it
     *                             when the shop's prices include tax): "0.25"; zero or less is no charge
     * @param bool     $taxable    taxed as items of the tax group are, where the order is taxed
     * @param int|null $taxGroupId null for the shop's default tax group
     * @param list<int> $forProductIds the products it is charged for (the bottles a deposit is for): when some of
     *                                 them go back, their share of it goes back too; none for the whole order
     */
    public function __construct(
        public string $label,
        public string $amount,
        public int $quantity = 1,
        public bool $taxable = true,
        public ?int $taxGroupId = null,
        public array $forProductIds = [],
    ) {}
}
