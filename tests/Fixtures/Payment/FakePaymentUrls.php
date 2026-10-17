<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Payment;

use OrillaCart\Payment\Application\PaymentUrls;

final class FakePaymentUrls implements PaymentUrls
{
    public function returnUrl(string $gateway): string
    {
        return "https://shop.test/payments/{$gateway}/return";
    }

    public function cancelUrl(string $gateway): string
    {
        return "https://shop.test/payments/{$gateway}/cancel";
    }

    public function notificationUrl(string $gateway): string
    {
        return "https://shop.test/payments/{$gateway}/webhook";
    }
}
