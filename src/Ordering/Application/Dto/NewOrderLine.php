<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Application\Dto;

use OrillaCart\Catalog\Application\Dto\QuotedDownload;
use OrillaCart\Inventory\Application\Dto\TakenStock;

/**
 * An order line as checkout priced it. Amounts are decimal strings: the unit price and line total before tax,
 * and the line's tax.
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
     */
    public function __construct(
        public int $productId,
        public ?int $variationId,
        public array $optionIds,
        public array $options,
        public string $name,
        public ?string $sku,
        public int $quantity,
        public string $unitPrice,
        public string $total,
        public string $tax,
        public bool $ships,
        public array $taken,
        public array $downloads = [],
        public ?int $downloadLimit = null,
        public ?int $downloadExpiryDays = null,
        public int $backordered = 0,
        public bool $backorderShown = false,
        /** What coupons took off the total; the tax is on what is left. */
        public string $discount = '0',
        /** @var list<array{name: string, quantity: int}> what one bundle holds, for a bundle */
        public array $contents = [],
        /** @var list<int> for a bundle, the variations chosen and the optional products added */
        public array $bundleChoices = [],
        /** @var list<array{id: string, label: string, answer: string, value: string, price: ?string, upload_id: ?int}> the answers to the product's forms, as sold */
        public array $personalisation = [],
    ) {}
}
