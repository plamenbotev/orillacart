<?php

declare(strict_types=1);

namespace OrillaCart\Forms\Domain;

/**
 * The part of checkout a question goes with.
 */
enum FieldSection: string
{
    case Billing = 'billing';
    /** Asked only of orders that ship. */
    case Shipping = 'shipping';
    case Order = 'order';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn(self $section): string => $section->value, self::cases());
    }
}
