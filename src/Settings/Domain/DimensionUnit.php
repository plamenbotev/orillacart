<?php

declare(strict_types=1);

namespace OrillaCart\Settings\Domain;

enum DimensionUnit: string
{
    case Centimetre = 'cm';
    case Metre = 'm';
    case Millimetre = 'mm';
    case Inch = 'in';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
