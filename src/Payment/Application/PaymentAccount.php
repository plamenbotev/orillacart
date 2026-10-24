<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Application;

use OrillaCart\Extension\Payment\NamesAccounts;
use OrillaCart\Extension\Payment\PaymentGateway;
use OrillaCart\Extension\Settings;
use OrillaCart\Payment\Domain\Payment;

/** The provider's account or mode a payment was made in (NamesAccounts), and whether the settings left it. */
final class PaymentAccount
{
    public static function of(PaymentGateway $gateway, Settings $settings): ?string
    {
        return $gateway instanceof NamesAccounts ? mb_substr($gateway->account($settings), 0, 100) : null;
    }

    /** Whether the settings point at another account or mode than the payment was made in. */
    public static function left(Payment $payment, PaymentGateway $gateway, Settings $settings): bool
    {
        $now = self::of($gateway, $settings);

        return $payment->account() !== null && $now !== null && $now !== $payment->account();
    }
}
