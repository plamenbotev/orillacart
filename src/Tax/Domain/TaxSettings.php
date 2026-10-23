<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Domain;

use OrillaCart\Shared\Domain\RuleViolation;

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
        /** Checkout asks business customers for their VAT number: those of other EU countries buy without VAT. */
        public bool $reverseCharge = false,
        /**
         * Each line's tax rounded to the currency and added up, as some tax offices and accounting systems expect;
         * otherwise each rate is rounded once over the order, which is closer to the exact tax.
         */
        public bool $roundsEachLine = false,
        /**
         * Regions that tax a sale within them at the seller's address ("US-TX"): a customer in the shop's own region,
         * when it is one of these, is taxed at the shop's address whatever the basis says, as Texas and Arizona
         * do. Sales into other regions follow the basis.
         *
         * @var list<string>
         */
        public array $originRegions = [],
    ) {
        foreach ($originRegions as $region) {
            if (preg_match(self::REGION, $region) !== 1) {
                throw new RuleViolation(\sprintf('"%s" is not a region: a country and a state code, such as US-TX.', $region), 'orillacart_invalid_region');
            }
        }
    }

    /** A country's code and one of its states': "US-TX". */
    public const string REGION = '/^[A-Z]{2}-[A-Z0-9]{1,3}$/';

    /**
     * The location tax is charged at: the basis's, unless the customer is in the shop's own region and it taxes
     * sales within it at the seller's address.
     */
    public function locate(?TaxLocation $shipping, ?TaxLocation $billing, ?TaxLocation $shop): ?TaxLocation
    {
        $location = $this->basis->location($shipping, $billing, $shop);
        if ($location !== null && $shop !== null && $shop->state !== null && $location->country === $shop->country && $location->state === $shop->state
            && \in_array("{$shop->country}-{$shop->state}", $this->originRegions, true)) {
            return $shop;
        }

        return $location;
    }

    public static function defaults(): self
    {
        return new self(TaxBasis::ShippingAddress, null);
    }

    public function equals(self $other): bool
    {
        return $this->basis === $other->basis && $this->defaultGroupId === $other->defaultGroupId && $this->pricesIncludeTax === $other->pricesIncludeTax
            && $this->reverseCharge === $other->reverseCharge && $this->roundsEachLine === $other->roundsEachLine && $this->originRegions === $other->originRegions;
    }
}
