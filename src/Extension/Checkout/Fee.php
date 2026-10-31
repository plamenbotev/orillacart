<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Checkout;

/**
 * A fee an order pays, as an OrderFee prices it.
 */
final readonly class Fee
{
    /**
     * @param string   $label      what the customer reads in the totals: "Cash on delivery fee"; up to 100 characters
     * @param string   $amount     in the order's currency, as the shop's prices are: before tax, or including it when
     *                             the shop's prices include tax; "2.50". Zero or less is no fee, and an answer that
     *                             is not a number is logged and left out
     * @param bool     $taxable    taxed as an item of the tax group is, where the order is taxed
     * @param int|null $taxGroupId null for the shop's default tax group
     */
    public function __construct(
        public string $label,
        public string $amount,
        public bool $taxable = true,
        public ?int $taxGroupId = null,
        /** Shown in the cart as a line of its own, beside the items, not only in the totals (API 18). */
        public bool $asLine = false,
    ) {}
}
