<?php

declare(strict_types=1);

namespace OrillaCart\Settings\Domain;

use OrillaCart\Shared\Domain\Currency;
use OrillaCart\Shared\Domain\Money;
use OrillaCart\Shared\Domain\RuleViolation;

/**
 * How the shop prices and measures things, and where it is. Always complete and valid: a shop that never
 * saved its settings runs on the defaults.
 */
final readonly class StoreSettings
{
    public const int MAX_SIGN_LENGTH = 8;
    public const int DEFAULT_LOW_STOCK_THRESHOLD = 2;

    /** The decimals amounts are priced, charged and shown in: the currency's, or fewer when the shop chose so. */
    public int $priceDecimals;

    /** Fewer decimals than the currency has, as the shop chose (whole forints for PayPal); null for the currency's. */
    public ?int $fewerDecimals;

    /**
     * @param int|null     $decimals     fewer decimals than the currency has; null, or the currency's own, to follow it
     * @param string       $currencySign shown with amounts; empty to show the currency code
     * @param bool         $catalogOnly  products are shown but not sold
     * @param bool         $hidePrices   prices are not shown, and products are not sold online, unless a product says otherwise
     * @param list<string> $sellTo       countries (ISO 3166-1 alpha-2) the shop sells to; empty for everywhere
     */
    public function __construct(
        public Currency $currency,
        ?int $decimals,
        public WeightUnit $weightUnit,
        public DimensionUnit $dimensionUnit,
        /** ISO 3166-1 alpha-2 code of the country the shop sells from; null until the shop sets it. */
        public ?string $shopCountry = null,
        /** ISO 3166-2 subdivision code without the country prefix; only with a country that has states. */
        public ?string $shopState = null,
        public ?string $shopPostcode = null,
        public string $currencySign = '',
        public CurrencyPosition $currencyPosition = CurrencyPosition::AfterWithSpace,
        public bool $catalogOnly = false,
        public bool $hidePrices = false,
        public array $sellTo = [],
        /** Sales show the lowest price of the 30 days before them, as the EU's Omnibus directive asks. */
        public bool $showLowestPrice = false,
        /** Deleting the plugin removes the shop's data too: orders, products, settings and files. */
        public bool $removeDataOnUninstall = false,
        /** The shop hears when a stockroom holds this many of an item or fewer, unless the stockroom or item says otherwise. */
        public int $lowStockThreshold = self::DEFAULT_LOW_STOCK_THRESHOLD,
        /** Orders on hold for a payment made outside the shop are cancelled after this many days unpaid; null never. */
        public ?int $unpaidHoldDays = null,
        /** Customers may ask to return items this many days after their order shipped; null takes no returns. */
        public ?int $returnDays = null,
        /** The least the items may come to, after discounts, for the customer to order; null for no least. */
        public ?Money $minimumOrder = null,
        /** The day the reports count an order on: paid, or placed. Changing it counts every order again. */
        public ReportDay $reportDay = ReportDay::Paid,
    ) {
        if ($minimumOrder !== null && ($minimumOrder->isNegative() || $minimumOrder->isZero())) {
            throw new RuleViolation('A minimum order is more than nothing; leave it empty for none.', 'orillacart_invalid_minimum_order');
        }
        if ($unpaidHoldDays !== null && ($unpaidHoldDays < 1 || $unpaidHoldDays > 365)) {
            throw new RuleViolation('Unpaid orders are cancelled after 1 to 365 days, or never.', 'orillacart_invalid_unpaid_days');
        }
        if ($returnDays !== null && ($returnDays < 1 || $returnDays > 365)) {
            throw new RuleViolation('Customers return items within 1 to 365 days, or the shop takes no returns.', 'orillacart_invalid_return_days');
        }
        if ($lowStockThreshold < 0) {
            throw new RuleViolation('A low stock threshold is 0 or more.', 'orillacart_invalid_low_stock_threshold');
        }
        $most = $currency->decimals();
        if ($decimals !== null && ($decimals < 0 || $decimals > $most)) {
            throw new RuleViolation(
                'Amounts in %1$s have at most %2$d decimals.',
                'orillacart_invalid_price_decimals',
                values: [$currency->code, $most],
            );
        }
        $this->fewerDecimals = $decimals === $most ? null : $decimals;
        $this->priceDecimals = $decimals ?? $most;
        if ($shopState !== null && $shopCountry === null) {
            throw new RuleViolation('The shop state needs a shop country.', 'orillacart_invalid_shop_address');
        }
        if ($shopPostcode !== null && (trim($shopPostcode) === '' || mb_strlen($shopPostcode) > 20)) {
            throw new RuleViolation('A postcode has 1 to 20 characters.', 'orillacart_invalid_shop_address');
        }
        if (mb_strlen($currencySign) > self::MAX_SIGN_LENGTH || trim($currencySign) !== $currencySign) {
            throw new RuleViolation('A currency sign has up to %d characters, without spaces at either end.', 'orillacart_invalid_currency_sign', values: [self::MAX_SIGN_LENGTH]);
        }
        foreach ($sellTo as $country) {
            if (preg_match('/^[A-Z]{2}$/', $country) !== 1) {
                throw new RuleViolation('"%s" is not a country code.', 'orillacart_invalid_country', values: [$country]);
            }
        }
        if (\count($sellTo) !== \count(array_unique($sellTo))) {
            throw new RuleViolation('A country is listed twice.', 'orillacart_invalid_country');
        }
    }

    /** Whether the shop sells to addresses in the country. */
    public function sellsTo(string $country): bool
    {
        return $this->sellTo === [] || \in_array($country, $this->sellTo, true);
    }

    public static function defaults(): self
    {
        return new self(new Currency('EUR'), null, WeightUnit::Kilogram, DimensionUnit::Centimetre);
    }

    public function equals(self $other): bool
    {
        return $this->currency->equals($other->currency)
            && $this->fewerDecimals === $other->fewerDecimals
            && $this->weightUnit === $other->weightUnit
            && $this->dimensionUnit === $other->dimensionUnit
            && $this->shopCountry === $other->shopCountry
            && $this->shopState === $other->shopState
            && $this->shopPostcode === $other->shopPostcode
            && $this->currencySign === $other->currencySign
            && $this->currencyPosition === $other->currencyPosition
            && $this->catalogOnly === $other->catalogOnly
            && $this->hidePrices === $other->hidePrices
            && $this->sellTo === $other->sellTo
            && $this->showLowestPrice === $other->showLowestPrice
            && $this->removeDataOnUninstall === $other->removeDataOnUninstall
            && $this->lowStockThreshold === $other->lowStockThreshold
            && $this->unpaidHoldDays === $other->unpaidHoldDays
            && $this->returnDays === $other->returnDays
            && $this->minimumOrder?->toFixed() === $other->minimumOrder?->toFixed()
            && $this->reportDay === $other->reportDay;
    }
}
