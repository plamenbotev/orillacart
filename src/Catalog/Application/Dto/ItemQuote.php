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
     * @param int|null           $available how many can be sold now; null when stock does not limit it, as with backorders
     * @param bool               $forSale   published, purchasable, not marked unavailable, and its price shown
     * @param string|null        $weight    in the shop's weight unit; length, width and height in its dimension unit
     * @param list<QuotedDownload> $downloads what the buyer may download once paid
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
        public ?string $length = null,
        public ?string $width = null,
        public ?string $height = null,
        public array $downloads = [],
        public ?int $downloadLimit = null,
        public ?int $downloadExpiryDays = null,
        public ?int $shippingClassId = null,
        public string $backorders = 'no',
        /** How many are in stock when backorders lift the limit; null otherwise. */
        public ?int $inStock = null,
        /** Its sale price applies now. */
        public bool $onSale = false,
        /** @var list<int> its categories, each with those above it */
        public array $categoryIds = [],
        /** @var list<int> its brands, each with those above it */
        public array $brandIds = [],
        /** @var list<QuotedBundleItem> what one of it holds, when it is a bundle */
        public array $contents = [],
        /** How many it may owe at most when it takes backorders; null for no limit. */
        public ?int $backorderLimit = null,
        /** What tax services call it; null for its tax group's code. */
        public ?string $taxCode = null,
        /** Codes and automatic discounts come off it: not when a price rule that set its price says so. */
        public bool $coupons = true,
        /** The name in the shop's own language, where the shopper's differs (design 51): what the shop reads in the order. */
        public ?string $shopName = null,
        /**
         * Why it is not for sale, where it is not: self::UNPUBLISHED, NOT_PURCHASABLE, NONE_LEFT, PRICE_HIDDEN, or
         * BUNDLE_ITEM (an item of the bundle is not); null when it is.
         */
        public ?string $notForSale = null,
    ) {}

    public const string UNPUBLISHED = 'unpublished';
    public const string NOT_PURCHASABLE = 'not_purchasable';
    public const string NONE_LEFT = 'none_left';
    public const string PRICE_HIDDEN = 'price_hidden';
    public const string BUNDLE_ITEM = 'bundle_item';

    /** How many of this quantity the customer is told are on backorder: none unless the product says so. */
    public function shownBackorder(int $quantity): int
    {
        return $this->backorders === 'notify' && $this->inStock !== null ? max(0, $quantity - max(0, $this->inStock)) : 0;
    }
}
