<?php

declare(strict_types=1);

namespace OrillaCart\Setup\Domain;

/**
 * Where a shop may sell, as the setup offers it: its own country, the EU, Europe, the world, or a list.
 */
enum Regions: string
{
    case Home = 'home';
    case Eu = 'eu';
    case Europe = 'europe';
    case World = 'world';
    case Listed = 'list';

    /**
     * The UN's Europe but Russia and Belarus, where EU sanctions forbid many sales (a shop adds them by list), and
     * Kosovo, which has no ISO code.
     */
    public const array EUROPE = [
        'AD', 'AL', 'AT', 'AX', 'BA', 'BE', 'BG', 'CH', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI', 'FO', 'FR', 'GB', 'GG', 'GI', 'GR',
        'HR', 'HU', 'IE', 'IM', 'IS', 'IT', 'JE', 'LI', 'LT', 'LU', 'LV', 'MC', 'MD', 'ME', 'MK', 'MT', 'NL', 'NO', 'PL', 'PT', 'RO', 'RS',
        'SE', 'SI', 'SJ', 'SK', 'SM', 'UA', 'VA',
    ];

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
