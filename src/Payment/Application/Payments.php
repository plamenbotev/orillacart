<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Application;

use OrillaCart\Payment\Application\Dto\PaymentSession;
use OrillaCart\Payment\Application\Dto\PaymentStartView;
use OrillaCart\Payment\Application\Dto\StartPayment;
use OrillaCart\Payment\Domain\Payment;
use OrillaCart\Payment\Domain\PaymentMethodNotFound;
use OrillaCart\Payment\Domain\PaymentMethodRepository;
use OrillaCart\Payment\Domain\PaymentProviderUnavailable;
use OrillaCart\Payment\Domain\PaymentRepository;
use OrillaCart\Shared\Domain\Money;
use Psr\Clock\ClockInterface;

/**
 * Starting payments for placed orders. How they end arrives later, from the provider, as payment events.
 */
final readonly class Payments
{
    public function __construct(
        private PaymentRepository $payments,
        private PaymentMethodRepository $methods,
        private Gateways $gateways,
        private PaymentUrls $urls,
        private ClockInterface $clock,
    ) {}

    /**
     * @throws PaymentMethodNotFound      for a method that is not set up
     * @throws PaymentProviderUnavailable when the provider cannot start it; the payment is then failed
     */
    public function start(StartPayment $command): PaymentStartView
    {
        $method = $this->methods->findByGateway($command->gateway) ?? throw new PaymentMethodNotFound($command->gateway);
        $gateway = $this->gateways->get($command->gateway);

        $payment = new Payment($command->orderId, $command->gateway, Money::of($command->amount), $command->currency, $command->receiptUrl, $this->clock->now());
        $this->payments->save($payment);

        try {
            $start = $gateway->start(new PaymentSession(
                $payment->id(),
                $command->orderNumber,
                (string) $payment->amount(),
                $command->currency,
                $this->urls->returnUrl($gateway->id()),
                $this->urls->cancelUrl($gateway->id()),
            ), $method->settings());
        } catch (PaymentProviderUnavailable $e) {
            $payment->fail(null, $this->clock->now());
            $this->payments->save($payment);

            throw $e;
        }

        if ($start->reference !== null) {
            $payment->refer($start->reference);
            $this->payments->save($payment);
        }

        return new PaymentStartView($payment->id(), $start->redirectUrl);
    }
}
