<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Domain;

use OrillaCart\Shared\Domain\TaxCode;

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
     * @param int|null    $shippingClassId     the shipping class of the product and its variations; null for none
     * @param Backorders  $backorders          whether it sells on when its counted stock runs out; variations may differ
     * @param int|null    $backorderLimit      how many it may owe at most, as variations do unless they say; null for no limit
     * @param int|null    $lowStockThreshold   the shop hears when a stockroom holds this many or fewer, as of variations
     *                                         unless they say; null for the stockroom's or the shop's
     * @param string|null $taxCode             what tax services call it, over its tax group's; null for the group's
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
        public ?int $shippingClassId = null,
        public Backorders $backorders = Backorders::No,
        public ?int $backorderLimit = null,
        public ?int $lowStockThreshold = null,
        public ?string $taxCode = null,
    ) {
        self::validSku($sku);
        if ($taxCode !== null && preg_match(TaxCode::PATTERN, $taxCode) !== 1) {
            throw new CatalogRuleViolation(TaxCode::MESSAGE, 'orillacart_invalid_tax_code');
        }
        self::validBackorderLimit($backorderLimit);
        self::validLowStockThreshold($lowStockThreshold);
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
            $this->shippingClassId,
            $this->backorders,
            $this->backorderLimit,
            $this->lowStockThreshold,
            $this->taxCode,
        );
    }

    /**
     * @throws CatalogRuleViolation for a limit below one
     */
    public static function validBackorderLimit(?int $limit): void
    {
        if ($limit !== null && $limit < 1) {
            throw new CatalogRuleViolation('A backorder limit is at least 1, or empty for no limit.', 'orillacart_invalid_backorder_limit');
        }
    }

    /**
     * @throws CatalogRuleViolation for a threshold below zero
     */
    public static function validLowStockThreshold(?int $threshold): void
    {
        if ($threshold !== null && $threshold < 0) {
            throw new CatalogRuleViolation('A low stock threshold is 0 or more, or empty for the stockroom\'s or the shop\'s.', 'orillacart_invalid_low_stock_threshold');
        }
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
