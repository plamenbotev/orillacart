<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Application\Dto;

use OrillaCart\Catalog\Application\Dto\QuotedDownload;
use OrillaCart\Inventory\Application\Dto\TakenStock;

/**
 * An order line as checkout priced it: an item sold or given, the shipping or a fee. Amounts are decimal strings: the unit
 * price and line total before tax (including it when the order's prices do), each rate's tax on it, and the
 * changes to its price.
 */
final readonly class NewOrderLine
{
    /**
     * @param list<int>                                    $optionIds
     * @param list<array{attribute: string, option: string}> $options
     * @param list<TakenStock>                             $taken
     * @param list<QuotedDownload>                         $downloads
     * @param int  $backordered    how many of the quantity were not in stock
     * @param bool $backorderShown whether the customer is told
     * @param list<LineTaxView>    $taxes
     * @param list<AdjustmentView> $adjustments
     */
    public function __construct(
        public ?int $productId,
        public ?int $variationId,
        public array $optionIds,
        public array $options,
        public string $name,
        public ?string $sku,
        public int $quantity,
        public string $unitPrice,
        public string $total,
        public array $taxes,
        public bool $ships,
        public array $taken,
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
        /** "item", "given" (by a discount), "add_on" (kept by an add-on), "shipping" or "fee". */
        public string $type = 'item',
        /**
         * Its name, options, contents and answers in the shop's own language, where the shopper's differ (design 51).
         *
         * @var array{name?: string, options?: list<array{attribute: string, option: string}>, contents?: list<array{name: string, quantity: int}>, personalisation?: list<array{id: string, label: string, answer: string, value: string, price: ?string, upload_id: ?int}>}
         */
        public array $shopWords = [],
        /** For an item given: the discount's code and description. */
        public ?string $givenCode = null,
        public string $givenDescription = '',
        /** For an add-on's item: its line type's id and label. */
        public ?string $addOnType = null,
        public string $addOnLabel = '',
    ) {}
}
