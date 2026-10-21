<?php

declare(strict_types=1);

namespace OrillaCart\Settings\Domain;

use OrillaCart\Shared\Domain\Money;

/**
 * How a price converted into another currency is rounded, so it looks set rather than worked out: to the
 * currency's decimals, to whole units, or up to just under the next whole unit (19.99).
 */
enum PriceRounding: string
{
    case Decimals = 'decimals';
    case Whole = 'whole';
    case NinetyNine = 'ninety_nine';

    public function round(Money $price, int $decimals): Money
    {
        if ($price->isZero() || $this === self::Decimals) {
            return $price->rounded($decimals);
        }
        $whole = $price->rounded(0);
        if ($this === self::Whole || $decimals === 0) {
            return $whole->isZero() ? $price->rounded($decimals) : $whole;
        }
        // Up to the next whole unit, then one of its smallest steps less: 18.20 → 18.99, 18.99 → 18.99.
        $up = $whole->compare($price) < 0 ? $whole->add(Money::of('1')) : $whole;

        return $up->subtract(Money::fromTenThousandths(10 ** (Money::DECIMALS - $decimals)));
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
