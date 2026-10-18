<?php

declare(strict_types=1);

namespace OrillaCart\Inventory\Domain;

/**
 * How the shop speaks of a stockroom's delivery time. Times are kept in days either way, for comparing
 * stockrooms; in weeks they are whole weeks.
 */
enum DeliveryUnit: string
{
    case Days = 'days';
    case Weeks = 'weeks';

    public function days(): int
    {
        return $this === self::Weeks ? 7 : 1;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn(self $unit): string => $unit->value, self::cases());
    }
}
