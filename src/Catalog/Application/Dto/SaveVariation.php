<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Application\Dto;

use OrillaCart\Catalog\Domain\ProductDetails;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Command: add a variation to a product, or change one.
 */
final readonly class SaveVariation
{
    /**
     * @param list<int> $optionIds one saved option of each attribute the variation differs in
     */
    public function __construct(
        #[Assert\Count(min: 1, minMessage: 'Choose at least one option.')]
        #[Assert\All([new Assert\Positive()])]
        public array $optionIds,
        #[Assert\Regex(SaveProduct::AMOUNT, message: SaveProduct::AMOUNT_MESSAGE)]
        public string $price,
        #[Assert\Valid]
        public ?SaveSale $sale = null,
        #[Assert\Length(min: 1, max: ProductDetails::MAX_SKU_LENGTH)]
        public ?string $sku = null,
        #[Assert\Choice(callback: [SaveProduct::class, 'stockModes'])]
        public string $stockMode = 'tracked',
    ) {}
}
