<?php

declare(strict_types=1);

namespace OrillaCart\Settings\Domain;

enum WeightUnit: string
{
    case Kilogram = 'kg';
    case Gram = 'g';
    case Pound = 'lb';
    case Ounce = 'oz';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
