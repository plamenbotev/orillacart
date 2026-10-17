<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Domain;

/**
 * Whether shoppers see a product's price. A product whose price is hidden is shown but not sold online: the
 * shopper asks for the price.
 */
enum PriceVisibility: string
{
    /** As the shop's setting says. */
    case Shop = 'shop';
    case Shown = 'shown';
    case Hidden = 'hidden';

    public function isHidden(bool $shopHidesPrices): bool
    {
        return $this === self::Hidden || ($this === self::Shop && $shopHidesPrices);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn(self $visibility): string => $visibility->value, self::cases());
    }
}
