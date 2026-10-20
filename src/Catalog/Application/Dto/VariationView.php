<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Application\Dto;

use OrillaCart\Catalog\Domain\AttributeOption;
use OrillaCart\Catalog\Domain\Variation;

final readonly class VariationView
{
    /**
     * @param list<int> $optionIds
     * @param string      $label     the option names, such as "M / Blue"
     * @param string|null $weight    its own; null when it weighs what the product does (as for length, width, height)
     */
    public function __construct(
        public int $id,
        public array $optionIds,
        public string $label,
        public string $price,
        public ?SaleView $sale,
        public ?string $sku,
        public string $stockMode,
        public ?string $weight = null,
        public ?string $length = null,
        public ?string $width = null,
        public ?string $height = null,
        /** null when it follows the product */
        public ?string $backorders = null,
        /** null when it follows the product */
        public ?int $backorderLimit = null,
        /** null when it follows the product */
        public ?int $lowStockThreshold = null,
    ) {}

    public static function fromVariation(Variation $variation): self
    {
        $options = $variation->options();

        return new self(
            $variation->id(),
            array_map(static fn(AttributeOption $option): int => $option->id(), $options),
            implode(' / ', array_map(static fn(AttributeOption $option): string => $option->name(), $options)),
            (string) $variation->pricing()->price(),
            SaleView::fromSale($variation->pricing()->sale()),
            $variation->sku(),
            $variation->stockMode()->value,
            $variation->weight(),
            $variation->length(),
            $variation->width(),
            $variation->height(),
            $variation->backorders()?->value,
            $variation->backorderLimit(),
            $variation->lowStockThreshold(),
        );
    }
}
