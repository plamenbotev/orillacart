<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Payment;

use OrillaCart\Extension\ProviderUnavailable;
use OrillaCart\Extension\Settings;

/**
 * A gateway that can ask its provider how a refund stands, for refunds it accepted to finish later
 * (RefundResult::pending()). The order learns of such a refund only once it is done; the shop asks every hour.
 * Since version 3.
 */
interface ChecksRefunds extends RefundsPayments
{
    /**
     * @param string $reference the provider's id of the refund, as RefundResult gave it
     *
     * @return RefundResult done(), or pending() while it still waits
     *
     * @throws RefundRefused       when the provider gave up on it
     * @throws ProviderUnavailable
     */
    public function checkRefund(string $reference, Settings $settings): RefundResult;
}
