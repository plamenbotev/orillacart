<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Domain;

use OrillaCart\Shared\Domain\Money;

/**
 * How an option changes the product price: by an amount, or by a percentage of the product's price (on sale
 * when it is). Percentages do not build on each other: each is of the product's price, not of the price with
 * the other options.
 */
enum AdjustmentKind: string
{
    case Amount = 'amount';
    case Percent = 'percent';

    /** The most a percentage may add; taking off more than 100 % would make the price negative. */
    public const string MAX_PERCENT = '999.9999';

    /**
     * @param Money $adjustment the amount, or the percentage as a decimal ("15" for 15 %)
     */
    public function of(Money $adjustment, Money $price): Money
    {
        if ($this === self::Amount) {
            return $adjustment;
        }
        $share = $price->percent(ltrim($adjustment->toFixed(), '-'));

        return $adjustment->isNegative() ? Money::zero()->subtract($share) : $share;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn(self $kind): string => $kind->value, self::cases());
    }
}
