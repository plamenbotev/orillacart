<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Application\Dto;

use OrillaCart\Catalog\Domain\AttributeOption;
use OrillaCart\Catalog\Domain\Variation;

final readonly class VariationView
{
    /**
     * @param list<int> $optionIds
     * @param string    $label     the option names, such as "M / Blue"
     */
    public function __construct(
        public int $id,
        public array $optionIds,
        public string $label,
        public string $price,
        public ?SaleView $sale,
        public ?string $sku,
        public string $stockMode,
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
        );
    }
}
