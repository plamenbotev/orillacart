<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Application\Dto;

/**
 * One way a product with variations is sold, as shoppers see it now.
 */
final readonly class OfferedVariation
{
    /**
     * @param list<int> $optionIds   in ascending order
     * @param string    $label       the option names, such as "M / Blue"
     * @param int|null  $available   null when stock does not limit it, as with backorders
     * @param bool      $onBackorder out of stock but sold on backorder, and the customer is to be told
     */
    public function __construct(
        public int $id,
        public array $optionIds,
        public string $label,
        public ?string $sku,
        public string $price,
        public ?string $regularPrice,
        public bool $forSale,
        public ?int $available,
        public bool $onBackorder = false,
        /** On sale: the lowest price of the 30 days before the sale price began, when known. */
        public ?string $lowestPrice = null,
        /** For sale but none in stock: a visitor may ask to hear when it is back. */
        public bool $soldOut = false,
        /** Once ShownPrices showed it: its price with and without tax. */
        public ?string $priceWithTax = null,
        public ?string $priceWithoutTax = null,
    ) {}

    /**
     * The variation with its prices as the shop shows them.
     *
     * @param \Closure(string): string $shown a price as shown
     */
    public function shown(\Closure $shown, string $withTax, string $withoutTax): self
    {
        $optional = static fn(?string $price): ?string => $price === null ? null : $shown($price);

        return new self($this->id, $this->optionIds, $this->label, $this->sku, $shown($this->price), $optional($this->regularPrice), $this->forSale, $this->available, $this->onBackorder, $optional($this->lowestPrice), $this->soldOut, $withTax, $withoutTax);
    }
}
