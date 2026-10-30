<?php

declare(strict_types=1);

namespace OrillaCart\Settings\Domain;

/**
 * The day the reports count a sale on: the day its money came, as filed periods want it, or the day it was placed.
 */
enum ReportDay: string
{
    case Paid = 'paid';
    case Placed = 'placed';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
