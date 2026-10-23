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
    ) {}

    /** How many of this quantity the customer is told are on backorder: none unless the product says so. */
    public function shownBackorder(int $quantity): int
    {
        return $this->backorders === 'notify' && $this->inStock !== null ? max(0, $quantity - max(0, $this->inStock)) : 0;
    }
}
