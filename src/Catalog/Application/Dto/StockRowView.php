<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Application\Dto;

/**
 * One line of the stock sheet: a product, a variation or an attribute option, and what each stockroom holds.
 */
final readonly class StockRowView
{
    /**
     * @param string           $kind      product, variation or option
     * @param int|null         $productId the product to edit for it; null for an option of a set
     * @param string           $owner     for an option, the set or product it belongs to; otherwise empty
     * @param string           $stockMode tracked, always_available or unavailable; options are always tracked
     * @param array<int, int>  $levels    by stockroom id; a stockroom without a level of the item is left out
     */
    public function __construct(
        public string $kind,
        public int $id,
        public ?int $productId,
        public string $name,
        public string $owner,
        public ?string $sku,
        public string $stockMode,
        public array $levels,
    ) {}
}
