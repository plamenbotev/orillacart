<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Application\Dto;

/**
 * A product as shoppers see it now: what it costs today, whether it can be bought, and the choices it offers.
 */
final readonly class ProductOffer
{
    /**
     * @param string               $price        today's price before tax and options; for a product with variations, the lowest
     * @param string|null          $regularPrice the price without the running sale; null when no sale runs
     * @param string|null          $highestPrice the highest variation price, when the variations' prices differ
     * @param bool                 $forSale      published, purchasable, its price shown, and something of it is available
     * @param int|null             $available    how many of the product itself can be sold; null when stock does not limit it
     * @param list<AttributeView>  $attributes   what the customer chooses from; for a product with variations, only the options its variations use
     * @param list<OfferedVariation> $variations
     * @param list<string>         $downloads    the names of the files the buyer gets
     * @param bool                 $priceHidden  shoppers ask for the price: it is not shown, and the product is not sold online
     * @param bool                 $onBackorder  out of stock but sold on backorder, and the customer is to be told
     */
    public function __construct(
        public int $id,
        public string $name,
        public string $type,
        public ?string $sku,
        public string $price,
        public ?string $regularPrice,
        public ?string $highestPrice,
        public bool $forSale,
        public ?int $available,
        public bool $featured,
        public array $attributes,
        public array $variations,
        public array $downloads,
        public bool $priceHidden = false,
        public bool $onBackorder = false,
        /** @var list<array{min_quantity: int, price: string}> the price from each quantity where the shop's rules change it */
        public array $tiers = [],
        /** @var list<array{name: string, quantity: int}> what one holds, for a bundle */
        public array $contents = [],
    ) {}

    /** Whether the product goes in the cart as it is, with no options to choose. */
    public function isSimple(): bool
    {
        return $this->attributes === [] && $this->variations === [];
    }
}
