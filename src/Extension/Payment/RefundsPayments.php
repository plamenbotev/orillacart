<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Payment;

use OrillaCart\Extension\ProviderUnavailable;
use OrillaCart\Extension\Settings;

/**
 * A gateway whose provider can give money back. The shop then offers "Refund through …" on paid orders; for
 * other gateways it records a refund the shop made outside, such as a bank transfer back.
 */
interface RefundsPayments
{
    /**
     * Gives back part or all of a completed payment. The shop sends the same RefundRequest::$id when it tries a
     * refund again after the provider could not be reached, so a gateway that passes it to the provider as an
     * idempotency key never refunds twice.
     *
     * @throws RefundRefused       the provider said no, such as for more than is left to refund
     * @throws ProviderUnavailable the provider could not be reached or did not answer; the shop can try again
     */
    public function refund(RefundRequest $request, Settings $settings): RefundResult;
}
