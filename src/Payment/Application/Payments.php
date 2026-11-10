<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Application;

use OrillaCart\Extension\Payment\PaymentSession;
use OrillaCart\Extension\ProviderUnavailable;
use OrillaCart\Extension\Settings;
use OrillaCart\Payment\Application\Dto\PaymentStartView;
use OrillaCart\Payment\Application\Dto\StartPayment;
use OrillaCart\Payment\Domain\Payment;
use OrillaCart\Payment\Domain\PaymentMethodNotFound;
use OrillaCart\Payment\Domain\PaymentMethodRepository;
use OrillaCart\Payment\Domain\PaymentProviderUnavailable;
use OrillaCart\Payment\Domain\PaymentRepository;
use OrillaCart\Shared\Domain\Money;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

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
        private LoggerInterface $logger,
    ) {}

    /**
     * @throws PaymentMethodNotFound      for a method that is not set up
     * @throws PaymentProviderUnavailable when the provider cannot start it; the payment is then failed
     */
    public function start(StartPayment $command): PaymentStartView
    {
        $method = $this->methods->findByGateway($command->gateway) ?? throw new PaymentMethodNotFound($command->gateway);
        $gateway = $this->gateways->get($command->gateway);

        $payment = new Payment($command->orderId, $command->gateway, Money::of($command->amount), $command->currency, $this->clock->now(), PaymentAccount::of($gateway, new Settings($method->settings())));
        $this->payments->save($payment);

        try {
            $start = $gateway->start(new PaymentSession(
                $payment->id(),
                $command->orderNumber,
                (string) $payment->amount(),
                $command->currency,
                $this->urls->returnUrl($gateway->id(), $payment->id()),
                $this->urls->cancelUrl($gateway->id(), $payment->id()),
                $this->urls->notificationUrl($gateway->id()),
                $command->items,
                $command->breakdown,
                $command->shipTo,
                $command->email,
                $command->payBy,
            ), new Settings($method->settings()));
        } catch (ProviderUnavailable $e) {
            $payment->fail(null, $this->clock->now());
            $this->payments->save($payment);

            throw new PaymentProviderUnavailable($e->provider, $e->getMessage(), $e);
        } catch (\Throwable $e) {
            // An add-on's gateway that broke: to the customer, as a provider that is not there.
            $this->logger->error('Payment method {gateway} failed to start the payment of order {order}: {message}', ['gateway' => $gateway->id(), 'order' => $command->orderId, 'message' => $e->getMessage(), 'exception' => $e]);
            $payment->fail(null, $this->clock->now());
            $this->payments->save($payment);

            throw new PaymentProviderUnavailable($method->name(), $e->getMessage(), $e);
        }

        if ($start->redirectUrl !== null && !self::isHttps($start->redirectUrl)) {
            // The customer's browser goes where it says: never a script, never in the clear.
            $this->logger->error('Payment method {gateway} sent the payment of order {order} to an address that is not https: {url}', ['gateway' => $gateway->id(), 'order' => $command->orderId, 'url' => $start->redirectUrl]);
            $payment->fail(null, $this->clock->now());
            $this->payments->save($payment);

            throw new PaymentProviderUnavailable($method->name(), 'The payment method gave an address that is not https.');
        }
        if ($start->reference !== null) {
            $payment->refer($start->reference);
            $this->payments->save($payment);
        }

        return new PaymentStartView($payment->id(), $start->redirectUrl);
    }

    /**
     * Whether a payment of the order waits for the shop to look at it: its money may have been taken, so the order
     * is not paid again meanwhile.
     */
    public function isHeld(int $orderId): bool
    {
        return array_any($this->payments->ofOrder($orderId), static fn(Payment $payment): bool => $payment->isHeld());
    }

    /** Whether a redirect goes over https to a host: nothing a browser reads another way (`javascript:`, `//`, `\\`). */
    public static function isHttps(string $url): bool
    {
        if (preg_match('/[\x00-\x20\x7f\\\\]/', $url) === 1 || preg_match('#^https://[^/?\#@:]#i', $url) !== 1) {
            return false;
        }
        $host = parse_url($url, \PHP_URL_HOST);

        return \is_string($host) && $host !== '';
    }
}
