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
     * @param int|null    $taxGroupId          null for the shop's default tax group
     * @param int|null    $downloadLimit       how many times a buyer may download each file; null for no limit
     * @param int|null    $downloadExpiryDays  how many days after payment the downloads work; null for always
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
        public ?int $downloadLimit = null,
        public ?int $downloadExpiryDays = null,
        public PriceVisibility $priceVisibility = PriceVisibility::Shop,
    ) {
        self::validSku($sku);
        if (($downloadLimit !== null && $downloadLimit < 1) || ($downloadExpiryDays !== null && $downloadExpiryDays < 1)) {
            throw new CatalogRuleViolation('A download limit and expiry are at least 1, or empty for none.', 'orillacart_invalid_download_terms');
        }
        self::validMeasures(['weight' => $weight, 'length' => $length, 'width' => $width, 'height' => $height]);
    }

    /** The same details at another price, the sale kept. */
    public function withPrice(Pricing $pricing): self
    {
        return new self(
            $this->type,
            $pricing,
            $this->sku,
            $this->taxable,
            $this->taxGroupId,
            $this->stockMode,
            $this->purchasable,
            $this->featured,
            $this->weight,
            $this->length,
            $this->width,
            $this->height,
            $this->downloadLimit,
            $this->downloadExpiryDays,
            $this->priceVisibility,
        );
    }

    /**
     * @param array<string, ?string> $measures by name; null for none
     *
     * @throws CatalogRuleViolation
     */
    public static function validMeasures(array $measures): void
    {
        foreach ($measures as $name => $value) {
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
