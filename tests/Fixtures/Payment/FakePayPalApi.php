<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Payment;

use OrillaCart\Payment\Application\Dto\PayPalAccount;
use OrillaCart\Payment\Application\Dto\PayPalCapture;
use OrillaCart\Payment\Application\Dto\PayPalNotification;
use OrillaCart\Payment\Application\Dto\PayPalOrder;
use OrillaCart\Payment\Application\Dto\PayPalOrderRequest;
use OrillaCart\Payment\Application\PayPal\PayPalApi;
use OrillaCart\Payment\Domain\PaymentProviderUnavailable;

/**
 * Plays PayPal: records the orders asked for and answers captures as the test sets them up.
 */
final class FakePayPalApi implements PayPalApi
{
    /** @var list<PayPalOrderRequest> */
    public array $created = [];
    /** @var list<string> */
    public array $captured = [];
    public bool $reachable = true;
    public string $captureStatus = 'COMPLETED';
    public ?string $capturedAmount = null;
    public bool $signed = true;

    public function createOrder(PayPalAccount $account, PayPalOrderRequest $request): PayPalOrder
    {
        $this->reach();
        $this->created[] = $request;
        $id = 'PAYPAL-' . \count($this->created);

        return new PayPalOrder($id, "https://www.sandbox.paypal.com/checkoutnow?token={$id}");
    }

    public function captureOrder(PayPalAccount $account, string $orderId): PayPalCapture
    {
        $this->reach();
        $this->captured[] = $orderId;
        $index = (int) substr($orderId, \strlen('PAYPAL-')) - 1;
        $request = $this->created[$index] ?? throw new PaymentProviderUnavailable('PayPal', 'unknown order');

        return new PayPalCapture("CAPTURE-{$orderId}", $this->captureStatus, $this->capturedAmount ?? $request->amount, $request->currency, $orderId);
    }

    public function verifyNotification(PayPalAccount $account, PayPalNotification $notification): bool
    {
        $this->reach();

        return $this->signed;
    }

    private function reach(): void
    {
        if (!$this->reachable) {
            throw new PaymentProviderUnavailable('PayPal', 'connection refused');
        }
    }
}
