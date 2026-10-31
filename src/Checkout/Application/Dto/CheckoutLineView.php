<?php

declare(strict_types=1);

namespace OrillaCart\Checkout\Application\Dto;

/**
 * A line of the order being placed: prices before tax, and its tax (exact; the totals round per tax rate).
 */
final readonly class CheckoutLineView
{
    /**
     * @param list<array{attribute: string, option: string}> $options
     * @param int $backordered how many of the quantity are on backorder, when the customer is to be told
     */
    public function __construct(
        public string $name,
        public array $options,
        public int $quantity,
        public string $unitPrice,
        public string $total,
        public string $tax,
        public int $backordered = 0,
        /** What coupons take off the total; the tax is on what is left. */
        public string $discount = '0',
        /** @var list<array{name: string, quantity: int}> what one bundle holds */
        public array $contents = [],
        /** An item a discount gives with the order. */
        public bool $gift = false,
        /** @var list<array{label: string, answer: string}> the answers to the product's forms */
        public array $personalisation = [],
        /** For an item given: the discount that gives it, as the shop describes it. */
        public ?string $givenWith = null,
        /** For an add-on's item: its line type's label. */
        public ?string $addOnLabel = null,
    ) {}
}
