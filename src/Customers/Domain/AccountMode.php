<?php

declare(strict_types=1);

namespace OrillaCart\Customers\Domain;

/**
 * Whether customers have accounts with the shop.
 */
enum AccountMode: string
{
    /** Customers may make an account when they order, or order as guests. */
    case Optional = 'optional';
    /** Customers must log in or make an account to order. */
    case Required = 'required';
    /** Everyone orders as a guest. */
    case Disabled = 'disabled';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
