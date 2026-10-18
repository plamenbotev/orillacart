<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Payment;

use OrillaCart\Extension\Payment\RefundRefused;
use OrillaCart\Payment\Application\Dto\PayPalAccount;
use OrillaCart\Payment\Application\Dto\PayPalCapture;
use OrillaCart\Payment\Application\Dto\PayPalNotification;
use OrillaCart\Payment\Application\Dto\PayPalOrder;
use OrillaCart\Payment\Application\Dto\PayPalOrderRequest;
use OrillaCart\Payment\Application\Dto\PayPalRefund;
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
    /** What PayPal's library throws next, as a bug would: not the unavailable provider a gateway expects. */
    public ?\Throwable $breaks = null;
    public string $captureStatus = 'COMPLETED';
    public ?string $capturedAmount = null;
    public bool $signed = true;
    /** @var array<string, array{capture: string, amount: string, currency: string}> refunds taken, by request id */
    public array $refunds = [];
    /** Why PayPal refuses the next refunds; null: it takes them. */
    public ?string $refuses = null;
    /** PayPal takes the refund but the answer is lost on the way. */
    public bool $answerLost = false;
    /** @var (\Closure(): void)|null what else happens while PayPal makes the order, such as a second submit */
    public ?\Closure $whileCreating = null;

    public function createOrder(PayPalAccount $account, PayPalOrderRequest $request): PayPalOrder
    {
        $this->reach();
        if ($this->whileCreating !== null) {
            ($this->whileCreating)();
        }
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

    public function refundCapture(PayPalAccount $account, string $captureId, string $requestId, string $amount, string $currency, string $note): PayPalRefund
    {
        $this->reach();
        if ($this->refuses !== null) {
            throw new RefundRefused($this->refuses);
        }
        // PayPal refunds once per request id.
        $this->refunds[$requestId] ??= ['capture' => $captureId, 'amount' => $amount, 'currency' => $currency];
        if ($this->answerLost) {
            throw new PaymentProviderUnavailable('PayPal', 'timed out');
        }

        return new PayPalRefund('REFUND-' . ((int) array_search($requestId, array_keys($this->refunds), true) + 1), 'COMPLETED');
    }

    public function verifyNotification(PayPalAccount $account, PayPalNotification $notification): bool
    {
        $this->reach();

        return $this->signed;
    }

    private function reach(): void
    {
        if ($this->breaks !== null) {
            throw $this->breaks;
        }
        if (!$this->reachable) {
            throw new PaymentProviderUnavailable('PayPal', 'connection refused');
        }
    }
}
