<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Application\PayPal;

use OrillaCart\Extension\Payment\RefundRefused;
use OrillaCart\Payment\Application\Dto\PayPalAccount;
use OrillaCart\Payment\Application\Dto\PayPalCapture;
use OrillaCart\Payment\Application\Dto\PayPalNotification;
use OrillaCart\Payment\Application\Dto\PayPalOrder;
use OrillaCart\Payment\Application\Dto\PayPalOrderRequest;
use OrillaCart\Payment\Application\Dto\PayPalRefund;
use OrillaCart\Payment\Domain\PaymentProviderUnavailable;

/**
 * PayPal's REST API (Orders v2 and webhook verification), as the PayPal gateway uses it.
 */
interface PayPalApi
{
    /**
     * @throws PaymentProviderUnavailable
     */
    public function createOrder(PayPalAccount $account, PayPalOrderRequest $request): PayPalOrder;

    /**
     * Captures an approved order; for an order captured before, returns that capture.
     *
     * @throws PaymentProviderUnavailable also when the order is not approved
     */
    public function captureOrder(PayPalAccount $account, string $orderId): PayPalCapture;

    /**
     * Refunds part or all of a capture. PayPal refunds once per request id, however often it is sent.
     *
     * @param string $amount formatted with the currency's decimals
     *
     * @throws RefundRefused              PayPal said no, such as for more than is left
     * @throws PaymentProviderUnavailable
     */
    public function refundCapture(PayPalAccount $account, string $captureId, string $requestId, string $amount, string $currency, string $note): PayPalRefund;

    /**
     * How a capture stands now.
     *
     * @throws PaymentProviderUnavailable
     */
    public function capture(PayPalAccount $account, string $captureId): PayPalCapture;

    /**
     * How an order stands now: its status, and its capture once captured; null for an order PayPal no longer
     * knows (one never approved expires).
     *
     * @return array{status: string, capture: ?PayPalCapture}|null
     *
     * @throws PaymentProviderUnavailable
     */
    public function order(PayPalAccount $account, string $orderId): ?array;

    /**
     * How a refund stands now.
     *
     * @throws PaymentProviderUnavailable
     */
    public function refund(PayPalAccount $account, string $refundId): PayPalRefund;

    /**
     * Whether PayPal signed this webhook call for the account's webhook.
     *
     * @throws PaymentProviderUnavailable
     */
    public function verifyNotification(PayPalAccount $account, PayPalNotification $notification): bool;
}
