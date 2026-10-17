<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Application\PayPal;

use OrillaCart\Payment\Application\Dto\PayPalAccount;
use OrillaCart\Payment\Application\Dto\PayPalCapture;
use OrillaCart\Payment\Application\Dto\PayPalNotification;
use OrillaCart\Payment\Application\Dto\PayPalOrder;
use OrillaCart\Payment\Application\Dto\PayPalOrderRequest;
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
     * Whether PayPal signed this webhook call for the account's webhook.
     *
     * @throws PaymentProviderUnavailable
     */
    public function verifyNotification(PayPalAccount $account, PayPalNotification $notification): bool;
}
