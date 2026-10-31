<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Application\Stripe;

/**
 * Stripe refused a request for what it asked, not for being away: its message, and its error code, such as
 * "charge_already_refunded" or "resource_missing".
 */
final class StripeRefused extends \RuntimeException
{
    public function __construct(string $message, public readonly string $stripeCode = '')
    {
        parent::__construct($message);
    }
}
