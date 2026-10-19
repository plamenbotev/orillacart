<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Domain;

use OrillaCart\Shared\Domain\Money;

/**
 * From this many items on, the price is this: a percentage or an amount off, or a price of its own.
 */
final readonly class PriceTier
{
    public const string PERCENT = 'percent';
    public const string AMOUNT_OFF = 'amount_off';
    public const string FIXED_PRICE = 'fixed_price';

    /**
     * @throws CatalogRuleViolation for a tier that cannot price anything
     */
    public function __construct(
        public int $minQuantity,
        public string $kind,
        public Money $value,
    ) {
        if ($minQuantity < 1) {
            throw new CatalogRuleViolation('A tier starts at 1 item or more.', 'orillacart_invalid_price_tier');
        }
        if (!\in_array($kind, [self::PERCENT, self::AMOUNT_OFF, self::FIXED_PRICE], true) || $value->isNegative()) {
            throw new CatalogRuleViolation('A tier takes a percentage or an amount off, or sets a price, of zero or more.', 'orillacart_invalid_price_tier');
        }
        if ($kind === self::PERCENT && !$value->isPercentOff()) {
            throw new CatalogRuleViolation('A percentage is at most 100.', 'orillacart_invalid_price_tier');
        }
    }

    public function apply(Money $price): Money
    {
        return match ($this->kind) {
            self::PERCENT => $price->subtract($price->percent((string) $this->value))->atLeastZero(),
            self::AMOUNT_OFF => $price->subtract($this->value)->atLeastZero(),
            default => $this->value,
        };
    }
}
