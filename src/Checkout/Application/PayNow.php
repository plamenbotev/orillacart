<?php

declare(strict_types=1);

namespace OrillaCart\Checkout\Application;

use OrillaCart\Checkout\Application\Dto\PayOrder;
use OrillaCart\Checkout\Application\Dto\PlacedOrderView;
use OrillaCart\Checkout\Domain\CheckoutRuleViolation;
use OrillaCart\Inventory\Domain\InsufficientStock;
use OrillaCart\Ordering\Application\Dto\OrderView;
use OrillaCart\Ordering\Application\OrderLinks;
use OrillaCart\Ordering\Application\OrderService;
use OrillaCart\Ordering\Application\ReceiptLinkExpired;
use OrillaCart\Ordering\Domain\OrderNotFound;
use OrillaCart\Ordering\Domain\OrderRuleViolation;
use OrillaCart\Payment\Application\Dto\PaymentOptionView;
use OrillaCart\Payment\Application\Dto\StartPayment;
use OrillaCart\Payment\Application\PaymentOptions;
use OrillaCart\Payment\Application\Payments;
use OrillaCart\Payment\Domain\PaymentProviderUnavailable;
use OrillaCart\Shared\Domain\Money;

/**
 * Paying an order later, from its receipt: one waiting for its money (an offline payment the customer would
 * rather make online, a provider page they closed) or one whose payment failed. The customer may choose any
 * method the shop offers for the billing country; the order then waits for that payment.
 */
final readonly class PayNow
{
    public function __construct(
        private OrderService $orders,
        private PaymentOptions $paymentOptions,
        private Payments $payments,
        private OrderLinks $links,
    ) {}

    /**
     * The ways the order can be paid now; none when it is paid, closed or being fulfilled.
     *
     * @return list<PaymentOptionView>
     */
    public function methods(OrderView $order): array
    {
        return $order->payable && !Money::of($order->total)->isZero() ? $this->paymentOptions->available($order->billing->country, $order->currency) : [];
    }

    /**
     * @throws OrderNotFound              for a receipt link the shop never made
     * @throws ReceiptLinkExpired         for one too old
     * @throws CheckoutRuleViolation      for an order that cannot be paid now, a method the shop does not offer
     *                                    for it, or a failed order whose items sold out since
     * @throws PaymentProviderUnavailable when the payment cannot start; the order stays as it was
     */
    public function pay(string $receipt, PayOrder $command): PlacedOrderView
    {
        $order = $this->orders->byReceipt($receipt);
        if (!$order->payable) {
            throw new CheckoutRuleViolation('This order cannot be paid now.', 'orillacart_order_not_payable');
        }
        if (!$this->paymentOptions->isAvailable($command->payment, $order->billing->country, $order->currency)) {
            throw new CheckoutRuleViolation('Choose how to pay.', 'orillacart_payment_required');
        }
        $failed = $order->status === 'failed';
        try {
            $this->orders->awaitPayment($order->number, $command->payment, $this->paymentOptions->name($command->payment), $this->paymentOptions->receiptNote($command->payment));
        } catch (InsufficientStock) {
            throw new CheckoutRuleViolation('Some of the items sold out since: this order cannot be paid any more. Order them again from the shop.', 'orillacart_out_of_stock');
        } catch (OrderRuleViolation $e) {
            throw new CheckoutRuleViolation($e->getMessage(), 'orillacart_order_not_payable', $e);
        }
        $receiptUrl = $this->links->receiptUrl($order->number);
        try {
            $payment = $this->payments->start(StartPayment::forOrder($order, $command->payment));
        } catch (PaymentProviderUnavailable $e) {
            // An order that had failed fails again, giving its stock back; one that waited goes on waiting.
            if ($failed) {
                $this->orders->fail($order->number, 'The payment could not start.');
            }

            throw $e;
        }
        if ($payment->isOffline()) {
            // The shop heard of the order when it was placed.
            $this->orders->hold($order->number, tellShop: false);
        }
        $placed = $this->orders->order($order->number);

        return new PlacedOrderView($placed->number, $placed->status, $placed->total, $receiptUrl, $payment->redirectUrl);
    }
}
