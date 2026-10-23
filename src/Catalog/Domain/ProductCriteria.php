<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Domain;

use OrillaCart\Shared\Domain\Money;

/**
 * Which products the shop's list shows, by their commerce details: every criterion given narrows it.
 */
final readonly class ProductCriteria
{
    public const string SHIPS = 'ships';
    public const string DOES_NOT_SHIP = 'does_not_ship';
    public const string WITH_VARIATIONS = 'variations';
    public const string BUNDLE = 'bundle';
    public const string WITH_DOWNLOADS = 'downloads';
    public const array KINDS = [self::SHIPS, self::DOES_NOT_SHIP, self::WITH_VARIATIONS, self::BUNDLE, self::WITH_DOWNLOADS];

    public const string FEATURED = 'featured';
    public const string ON_SALE = 'on_sale';
    public const string PRICE_HIDDEN = 'price_hidden';
    public const string NOT_PURCHASABLE = 'not_purchasable';
    public const array SHOWN = [self::FEATURED, self::ON_SALE, self::PRICE_HIDDEN, self::NOT_PURCHASABLE];

    public const string NO_SKU = 'sku';
    public const string NO_PRICE = 'price';
    public const string NO_WEIGHT = 'weight';
    /** What the product itself keeps; a picture and a category are its content's. */
    public const array MISSING = [self::NO_SKU, self::NO_PRICE, self::NO_WEIGHT];

    /**
     * @param string|null $kind          one of KINDS
     * @param string|null $shown         one of SHOWN
     * @param Money|null  $minPrice      what the shop charges now, the product's or a variation's
     * @param int|null    $shippingClass 0 for products without one
     * @param string|null $missing       one of MISSING: no SKU (the product's or a variation's), a zero price, or
     *                                   no weight on a product that ships
     */
    public function __construct(
        public ?string $kind = null,
        public ?string $shown = null,
        public ?Money $minPrice = null,
        public ?Money $maxPrice = null,
        public ?int $shippingClass = null,
        public ?int $attributeSet = null,
        public ?string $missing = null,
        /** The shop's setting: a product that follows it hides its price too. */
        public bool $shopHidesPrices = false,
        public \DateTimeImmutable $now = new \DateTimeImmutable(),
    ) {}

    public function narrows(): bool
    {
        return $this->kind !== null || $this->shown !== null || $this->minPrice !== null || $this->maxPrice !== null
            || $this->shippingClass !== null || $this->attributeSet !== null || $this->missing !== null;
    }
}
