<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Domain;

use OrillaCart\Shared\Domain\Money;

/**
 * A product with the customer's choices checked and priced: either one of its variations, or the product
 * with options whose adjustments are in the unit price.
 */
final readonly class Configuration
{
    /**
     * @param list<AttributeOption> $options the chosen options of a product without variations; empty for a variation
     */
    public function __construct(
        public Product $product,
        public ?Variation $variation,
        public array $options,
        public Money $unitPrice,
    ) {}

    public function sku(): ?string
    {
        return $this->variation !== null ? $this->variation->sku() : $this->product->details()->sku;
    }

    public function stockMode(): StockMode
    {
        return $this->variation !== null ? $this->variation->stockMode() : $this->product->details()->stockMode;
    }
}
