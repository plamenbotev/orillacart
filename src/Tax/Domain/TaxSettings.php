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
        /** The tax service working out the taxes, by its id; null for the shop's own rates. */
        public ?string $provider = null,
        /** When the service cannot be reached at checkout: SHOP_RATES to tax at the shop's own rates, or REFUSE. */
        public string $whenProviderFails = self::SHOP_RATES,
        /** Customers send their exemption certificates, at checkout or in their account, for the shop to check. */
        public bool $acceptsCertificates = false,
        /** How prices show in the shop's catalog: AS_ENTERED, WITH_TAX or WITHOUT_TAX. */
        public string $shownInShop = self::AS_ENTERED,
        /** How the cart and checkout show prices: AS_ENTERED, WITH_TAX or WITHOUT_TAX. */
        public string $shownInCart = self::AS_ENTERED,
        /**
         * Text after the catalog's prices, such as "incl. VAT"; {price_including_tax} and {price_excluding_tax} stand
         * for the price with and without tax.
         */
        public string $priceSuffix = '',
        /**
         * With prices entered including tax: every customer pays the price as entered and the tax in it is their own
         * rate's (true); or the price before the shop's own tax stays and the customer's rate goes on top (false).
         */
        public bool $samePriceEverywhere = true,
        /**
         * Shipping taxed as the items it carries: its price shared out by what they cost, each share at their rates,
         * as EU VAT treats it; otherwise at the tax group of the way to ship.
         */
        public bool $shippingTaxedAsItems = false,
        /**
         * The EU's threshold of sales to consumers in other EU countries in a calendar year, in the shop's currency:
         * below it a shop in the EU may charge its own VAT; above it, the customer's country's (OSS). Null for the
         * law's €10,000 (EU_THRESHOLD), converted to the shop's currency when it is another.
         */
        public ?string $euThreshold = null,
    ) {
        if ($euThreshold !== null && preg_match('/^\d{1,12}(\.\d{1,2})?$/', $euThreshold) !== 1) {
            throw new RuleViolation('The EU threshold is an amount such as 10000.', 'orillacart_invalid_tax_settings');
        }
        foreach ([$shownInShop, $shownInCart] as $shown) {
            if (!\in_array($shown, self::SHOWN, true)) {
                throw new RuleViolation('Choose whether prices show with tax, without it, or as they are entered.', 'orillacart_invalid_tax_settings');
            }
        }
        if (mb_strlen($priceSuffix) > self::SUFFIX_LENGTH || preg_match('/[<>\x00-\x1F]/', $priceSuffix) === 1) {
            throw new RuleViolation('The text after prices is plain text of up to %d characters.', 'orillacart_invalid_price_suffix', values: [self::SUFFIX_LENGTH]);
        }
        if (!\in_array($whenProviderFails, [self::SHOP_RATES, self::REFUSE], true)) {
            throw new RuleViolation('Choose what checkout does when the tax service cannot be reached.', 'orillacart_invalid_tax_settings');
        }
        foreach ($originRegions as $region) {
            if (preg_match(self::REGION, $region) !== 1) {
                throw new RuleViolation('"%s" is not a region: a country and a state code, such as US-TX.', 'orillacart_invalid_region', values: [$region]);
            }
        }
    }

    /** The order is taxed at the shop's own rates, and the shop is told. */
    public const string SHOP_RATES = 'shop_rates';
    /** Checkout asks the customer to try again: no order goes out with tax the service did not work out. */
    public const string REFUSE = 'refuse';

    public const string AS_ENTERED = 'as_entered';
    public const string WITH_TAX = 'with_tax';
    public const string WITHOUT_TAX = 'without_tax';
    public const array SHOWN = [self::AS_ENTERED, self::WITH_TAX, self::WITHOUT_TAX];
    public const int SUFFIX_LENGTH = 80;
    /** The EU's threshold, in euros. */
    public const string EU_THRESHOLD = '10000';

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

    /** Whether prices show with tax: in the cart and checkout, or in the catalog. */
    public function showsTax(bool $inCart): bool
    {
        $shown = $inCart ? $this->shownInCart : $this->shownInShop;

        return $shown === self::AS_ENTERED ? $this->pricesIncludeTax : $shown === self::WITH_TAX;
    }

    public function withEuThreshold(?string $threshold): self
    {
        return new self($this->basis, $this->defaultGroupId, $this->pricesIncludeTax, $this->reverseCharge, $this->roundsEachLine, $this->originRegions, $this->provider, $this->whenProviderFails, $this->acceptsCertificates, $this->shownInShop, $this->shownInCart, $this->priceSuffix, $this->samePriceEverywhere, $this->shippingTaxedAsItems, $threshold);
    }

    public static function defaults(): self
    {
        return new self(TaxBasis::ShippingAddress, null);
    }

    public function equals(self $other): bool
    {
        return $this->basis === $other->basis && $this->defaultGroupId === $other->defaultGroupId && $this->pricesIncludeTax === $other->pricesIncludeTax
            && $this->reverseCharge === $other->reverseCharge && $this->roundsEachLine === $other->roundsEachLine && $this->originRegions === $other->originRegions
            && $this->provider === $other->provider && $this->whenProviderFails === $other->whenProviderFails && $this->acceptsCertificates === $other->acceptsCertificates
            && $this->shownInShop === $other->shownInShop && $this->shownInCart === $other->shownInCart && $this->priceSuffix === $other->priceSuffix
            && $this->samePriceEverywhere === $other->samePriceEverywhere && $this->shippingTaxedAsItems === $other->shippingTaxedAsItems
            && $this->euThreshold === $other->euThreshold;
    }
}
