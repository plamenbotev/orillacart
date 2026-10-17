<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Domain;

/**
 * What the shop sells a product as. Its name, description and images are the WordPress post's.
 */
final readonly class ProductDetails
{
    public const int MAX_SKU_LENGTH = 64;

    /**
     * @param string|null $weight in the shop's weight unit, as a decimal string
     * @param string|null $length in the shop's dimension unit, as are width and height
     * @param int|null    $taxGroupId null for the shop's default tax group
     */
    public function __construct(
        public ProductType $type,
        public Pricing $pricing,
        public ?string $sku = null,
        public bool $taxable = true,
        public ?int $taxGroupId = null,
        public StockMode $stockMode = StockMode::Tracked,
        public bool $purchasable = true,
        public bool $featured = false,
        public ?string $weight = null,
        public ?string $length = null,
        public ?string $width = null,
        public ?string $height = null,
    ) {
        self::validSku($sku);
        foreach (['weight' => $weight, 'length' => $length, 'width' => $width, 'height' => $height] as $name => $value) {
            if ($value !== null && preg_match('/^\d{1,8}(\.\d{1,4})?$/', $value) !== 1) {
                throw new CatalogRuleViolation(\sprintf('The %s has to be a number of zero or more with up to four decimals.', $name), 'orillacart_invalid_measure');
            }
        }
    }

    /**
     * @throws CatalogRuleViolation
     */
    public static function validSku(?string $sku): void
    {
        if ($sku !== null && ($sku === '' || mb_strlen($sku) > self::MAX_SKU_LENGTH || trim($sku) !== $sku)) {
            throw new CatalogRuleViolation(\sprintf('A SKU has 1 to %d characters, without spaces at either end.', self::MAX_SKU_LENGTH), 'orillacart_invalid_sku');
        }
    }
}
