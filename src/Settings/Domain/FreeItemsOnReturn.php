<?php

declare(strict_types=1);

namespace OrillaCart\Settings\Domain;

/**
 * What becomes of a free item when items it came with go back, and what is kept no longer earns it (two of three
 * lamps bought, one given, and one bought sent back): it comes back with them, its price is kept from the
 * refund, or it stays the customer's.
 */
enum FreeItemsOnReturn: string
{
    case Charged = 'charged';
    case ComeBack = 'come_back';
    case StayFree = 'stay_free';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
