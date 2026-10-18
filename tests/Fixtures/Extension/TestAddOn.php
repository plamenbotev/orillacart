<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Extension;

use OrillaCart\Extension\Payment\PaymentGateway;
use OrillaCart\Extension\Payment\PaymentSession;
use OrillaCart\Extension\Payment\PaymentStart;
use OrillaCart\Extension\Registry;
use OrillaCart\Extension\Settings;

/**
 * An add-on, registered on orillacart_extensions as another plugin would be: a gateway and a shipping method of
 * its own, and a gateway whose id the shop already uses, which is left out.
 */
final class TestAddOn
{
    public static function register(Registry $registry): void
    {
        $registry->paymentGateway(new TestPayGateway());
        $registry->shippingMethod(new TestShipMethod());
        $registry->paymentGateway(new class implements PaymentGateway {
            public function id(): string
            {
                return 'cheque';
            }

            public function defaultName(): string
            {
                return 'An impostor';
            }

            public function settings(): array
            {
                return [];
            }

            public function isReady(Settings $settings, string $currency): bool
            {
                return true;
            }

            public function start(PaymentSession $session, Settings $settings): PaymentStart
            {
                return PaymentStart::offline();
            }
        });
    }
}
