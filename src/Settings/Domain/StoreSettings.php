<?php

declare(strict_types=1);

namespace OrillaCart\Settings\Domain;

use OrillaCart\Shared\Domain\Currency;
use OrillaCart\Shared\Domain\RuleViolation;

/**
 * How the shop prices and measures things, and where it is. Always complete and valid: a shop that never
 * saved its settings runs on the defaults.
 */
final readonly class StoreSettings
{
    public const int MAX_PRICE_DECIMALS = 4;

    public function __construct(
        public Currency $currency,
        public int $priceDecimals,
        public WeightUnit $weightUnit,
        public DimensionUnit $dimensionUnit,
        /** ISO 3166-1 alpha-2 code of the country the shop sells from; null until the shop sets it. */
        public ?string $shopCountry = null,
        /** ISO 3166-2 subdivision code without the country prefix; only with a country that has states. */
        public ?string $shopState = null,
    ) {
        if ($priceDecimals < 0 || $priceDecimals > self::MAX_PRICE_DECIMALS) {
            throw new RuleViolation(
                \sprintf('Prices can have between 0 and %d decimals.', self::MAX_PRICE_DECIMALS),
                'orillacart_invalid_price_decimals',
            );
        }
        if ($shopState !== null && $shopCountry === null) {
            throw new RuleViolation('The shop state needs a shop country.', 'orillacart_invalid_shop_address');
        }
    }

    public static function defaults(): self
    {
        return new self(new Currency('EUR'), 2, WeightUnit::Kilogram, DimensionUnit::Centimetre);
    }

    public function equals(self $other): bool
    {
        return $this->currency->equals($other->currency)
            && $this->priceDecimals === $other->priceDecimals
            && $this->weightUnit === $other->weightUnit
            && $this->dimensionUnit === $other->dimensionUnit
            && $this->shopCountry === $other->shopCountry
            && $this->shopState === $other->shopState;
    }
}
