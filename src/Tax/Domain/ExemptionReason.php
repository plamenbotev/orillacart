<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Domain;

/**
 * Why a customer buys without tax, as certificates and returns name it.
 */
enum ExemptionReason: string
{
    /** Buys to sell again, with a resale certificate. */
    case Resale = 'resale';
    case Nonprofit = 'nonprofit';
    case Government = 'government';
    case Other = 'other';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
