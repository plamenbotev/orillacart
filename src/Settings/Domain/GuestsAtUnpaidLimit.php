<?php

declare(strict_types=1);

namespace OrillaCart\Settings\Domain;

/**
 * What a guest is told once the place they order from holds as many orders waiting for a transfer as one shopper
 * may (StoreSettings::$unpaidOrdersEach): refused; or asked to confirm their email by a link sent to it, after
 * which their orders are counted by that mailbox, so people who share an address (an office, a campus) each
 * order. A customer with an account is counted by it either way.
 */
enum GuestsAtUnpaidLimit: string
{
    case ConfirmEmail = 'confirm_email';
    case Refuse = 'refuse';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
