<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Application\Dto;

use OrillaCart\Catalog\Domain\Backorders;
use OrillaCart\Catalog\Domain\ProductDetails;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Command: add a variation to a product, or change one.
 */
final readonly class SaveVariation
{
    /**
     * @param list<int>   $optionIds one saved option of each attribute the variation differs in
     * @param string|null $weight    null for the product's weight, as for length, width and height
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
        #[Assert\Regex(SaveProduct::MEASURE, message: SaveProduct::MEASURE_MESSAGE)]
        public ?string $weight = null,
        #[Assert\Regex(SaveProduct::MEASURE, message: SaveProduct::MEASURE_MESSAGE)]
        public ?string $length = null,
        #[Assert\Regex(SaveProduct::MEASURE, message: SaveProduct::MEASURE_MESSAGE)]
        public ?string $width = null,
        #[Assert\Regex(SaveProduct::MEASURE, message: SaveProduct::MEASURE_MESSAGE)]
        public ?string $height = null,
        /** null to follow the product; no, yes or notify */
        #[Assert\Choice(callback: [Backorders::class, 'values'])]
        public ?string $backorders = null,
        /** Null to follow the product. */
        #[Assert\Positive]
        public ?int $backorderLimit = null,
        /** Null to follow the product. */
        #[Assert\PositiveOrZero]
        public ?int $lowStockThreshold = null,
    ) {}

    /**
     * The command that saves the variation as it is, but for the changes, by argument name.
     *
     * @param array{optionIds?: list<int>, price?: string, sale?: ?SaveSale, sku?: ?string, stockMode?: string, weight?: ?string, length?: ?string, width?: ?string, height?: ?string, backorders?: ?string, backorderLimit?: ?int, lowStockThreshold?: ?int} $changes
     */
    public static function unchanged(VariationView $view, array $changes = []): self
    {
        return new self(...array_merge([
            'optionIds' => $view->optionIds,
            'price' => $view->price,
            'sale' => $view->sale === null ? null : new SaveSale($view->sale->price, $view->sale->from, $view->sale->until),
            'sku' => $view->sku,
            'stockMode' => $view->stockMode,
            'weight' => $view->weight,
            'length' => $view->length,
            'width' => $view->width,
            'height' => $view->height,
            'backorders' => $view->backorders,
            'backorderLimit' => $view->backorderLimit,
            'lowStockThreshold' => $view->lowStockThreshold,
        ], $changes));
    }
}
