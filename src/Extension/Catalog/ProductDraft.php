<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Catalog;

/**
 * A product an add-on makes or changes (API 26): what it gives is set, what it leaves null stays as it is (or the
 * shop's default, for a new product). Amounts are decimal strings ("19.90"); texts take the HTML a post may hold,
 * cleaned as for an author without unfiltered HTML.
 */
final readonly class ProductDraft
{
    /**
     * @param string|null                $type         physical or virtual
     * @param string|null                $salePrice    the sale price, running from now; "" ends the sale
     * @param list<string>|null          $categories   each as its path, "Lighting > Desk lamps"; missing ones are made with their parents
     * @param list<string>|null          $tags
     * @param int|null                   $stock        the level in the shop's first stockroom
     * @param array<int, int>|null       $stockrooms   levels by stockroom id, over $stock
     * @param string|null                $stockMode    tracked, or untracked: never runs out
     * @param list<AttributeDraft>|null  $attributes   the product's own attributes, in order; replaces those it had, keeping the ids of those named alike
     * @param list<int>|null             $attributeSetIds the attribute sets whose attributes it offers
     */
    public function __construct(
        public ?string $name = null,
        public ?string $price = null,
        public ?string $type = null,
        public ?string $sku = null,
        public ?string $salePrice = null,
        public ?string $description = null,
        public ?string $shortDescription = null,
        public ?array $categories = null,
        public ?array $tags = null,
        public ?int $stock = null,
        public ?array $stockrooms = null,
        public ?string $stockMode = null,
        public ?string $weight = null,
        public ?bool $featured = null,
        public ?array $attributes = null,
        public ?array $attributeSetIds = null,
    ) {}
}
