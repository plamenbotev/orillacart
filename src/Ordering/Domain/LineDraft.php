<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Domain;

use OrillaCart\Shared\Domain\Money;

/**
 * An order line as checkout priced it.
 */
final readonly class LineDraft
{
    /**
     * @param list<int>                                    $optionIds
     * @param list<array{attribute: string, option: string}> $options   the chosen options as the customer saw them
     * @param list<TakenItem>                              $taken
     * @param list<LineDownload>                           $downloads what the buyer may download once paid
     * @param int  $backordered    how many of the quantity were not in stock: the shop owes them
     * @param bool $backorderShown whether the customer is told of the backorder
     */
    public function __construct(
        public int $productId,
        public ?int $variationId,
        public array $optionIds,
        public array $options,
        public string $name,
        public ?string $sku,
        public int $quantity,
        public Money $unitPrice,
        public Money $total,
        public Money $tax,
        public bool $ships,
        public array $taken = [],
        public array $downloads = [],
        public ?int $downloadLimit = null,
        public ?int $downloadExpiryDays = null,
        public int $backordered = 0,
        public bool $backorderShown = false,
        /** What coupons took off the total. */
        public ?Money $discount = null,
    ) {}
}
