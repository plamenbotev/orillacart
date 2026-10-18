<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Domain;

/**
 * How the shop charges tax: which address decides, and the group for products and shipping rates that
 * name none. Without a default group, only items with their own group are taxed. Prices, product and shipping
 * alike, are entered before tax, or including it: then every customer pays the price as entered, and the tax in
 * it is their own rate's; a customer who owes no tax pays it less the shop's own.
 */
final readonly class TaxSettings
{
    public function __construct(
        public TaxBasis $basis,
        public ?int $defaultGroupId,
        public bool $pricesIncludeTax = false,
    ) {}

    public static function defaults(): self
    {
        return new self(TaxBasis::ShippingAddress, null);
    }

    public function equals(self $other): bool
    {
        return $this->basis === $other->basis && $this->defaultGroupId === $other->defaultGroupId && $this->pricesIncludeTax === $other->pricesIncludeTax;
    }
}
