<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Payment;

use OrillaCart\Payment\Application\PaymentOutcomes;
use OrillaCart\Payment\Application\PaymentUrls;
use OrillaCart\Shared\Security\Signatures;
use Psr\Clock\ClockInterface;

final readonly class FakePaymentUrls implements PaymentUrls
{
    public function __construct(
        private Signatures $signatures,
        private ClockInterface $clock,
    ) {}

    public function returnUrl(string $gateway, int $paymentId): string
    {
        return "https://shop.test/payments/{$gateway}/return?" . http_build_query($this->query($gateway, $paymentId));
    }

    public function cancelUrl(string $gateway, int $paymentId): string
    {
        return "https://shop.test/payments/{$gateway}/cancel?" . http_build_query($this->query($gateway, $paymentId));
    }

    public function notificationUrl(string $gateway): string
    {
        return "https://shop.test/payments/{$gateway}/webhook";
    }

    /**
     * What the shop signs into the return and cancel addresses of a payment.
     *
     * @return array{payment: string, day: string, sig: string}
     */
    public function query(string $gateway, int $paymentId): array
    {
        return PaymentOutcomes::returnQuery($this->signatures, $gateway, $paymentId, $this->clock->now());
    }
}
