<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Application\Dto;

/**
 * A product, or one of its variations, as it can be sold right now: what the cart and checkout need.
 */
final readonly class ItemQuote
{
    /**
     * @param list<QuotedOption> $options   the chosen options of a product without variations, with their adjustments
     * @param string             $unitPrice before tax, options included
     * @param int|null           $available how many can be sold now; null when stock does not limit it
     * @param bool               $forSale   published, purchasable and not marked unavailable
     */
    public function __construct(
        public int $productId,
        public ?int $variationId,
        public string $name,
        public ?string $sku,
        public string $type,
        public string $unitPrice,
        public bool $taxable,
        public ?int $taxGroupId,
        public ?string $weight,
        public array $options,
        public ?int $available,
        public bool $forSale,
    ) {}
}
