<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Domain;

use OrillaCart\Shared\Domain\Money;

/**
 * An order line as checkout priced it: an item sold or given, the shipping, or a fee, with the taxes charged on it and the
 * changes to its price.
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
     * @param list<LineTax>          $taxes       each rate charged on it
     * @param list<PriceAdjustment>  $adjustments what changed its price after its unit price: coupons, the shop
     */
    public function __construct(
        public ?int $productId,
        public ?int $variationId,
        public array $optionIds,
        public array $options,
        public string $name,
        public ?string $sku,
        public int $quantity,
        public Money $unitPrice,
        public Money $total,
        public array $taxes,
        public bool $ships,
        public array $taken = [],
        public array $downloads = [],
        public ?int $downloadLimit = null,
        public ?int $downloadExpiryDays = null,
        public int $backordered = 0,
        public bool $backorderShown = false,
        public array $adjustments = [],
        /** @var list<array{name: string, quantity: int}> what one bundle holds, for a bundle */
        public array $contents = [],
        /** @var list<int> for a bundle, the variations chosen and the optional products added */
        public array $bundleChoices = [],
        /** @var list<array{id: string, label: string, answer: string, value: string, price: ?string, upload_id: ?int}> the answers to the product's forms, as sold */
        public array $personalisation = [],
        public LineType $type = LineType::Item,
        /**
         * Its name, options, contents and answers in the shop's own language, where the shopper's differ (design 51).
         *
         * @var array{name?: string, options?: list<array{attribute: string, option: string}>, contents?: list<array{name: string, quantity: int}>, personalisation?: list<array{id: string, label: string, answer: string, value: string, price: ?string, upload_id: ?int}>}
         */
        public array $shopWords = [],
        /** For an item given: the discount that gave it. */
        public ?GivenBy $givenBy = null,
        /** For an add-on's item: its line type. */
        public ?KeptBy $addOn = null,
    ) {}
}
