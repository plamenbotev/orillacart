<?php

declare(strict_types=1);

namespace OrillaCart\Setup\Application\Check;

use OrillaCart\Payment\Application\PaymentMethodService;
use OrillaCart\Setup\Application\Dto\Readiness;
use OrillaCart\Setup\Application\ReadinessCheck;
use OrillaCart\Shared\Domain\Text;

/** A way to pay that works: on, and with all it needs. PayPal's sandbox is advice: it takes no real money. */
final readonly class PaymentReadiness implements ReadinessCheck
{
    public function __construct(private PaymentMethodService $methods) {}

    public function check(): array
    {
        $ready = [];
        $found = [];
        foreach ($this->methods->methods() as $method) {
            if (!$method->active) {
                continue;
            }
            if ($method->notReady !== null) {
                $found[] = Readiness::stop('payment.' . $method->gateway, Text::of('%1$s is on but cannot take payments: %2$s', $method->name, $method->notReady), 'payments');
                continue;
            }
            $ready[] = $method;
            if (($method->settings['sandbox'] ?? false) === true) {
                $found[] = Readiness::advice('payment.' . $method->gateway . '.sandbox', Text::of('%s takes test payments (sandbox): turn it off and give the live keys before selling.', $method->name), 'payments');
            }
        }
        if ($ready === []) {
            $found[] = Readiness::stop('payment.none', Text::of('No way to pay is on: customers cannot order.'), 'payments');
        }

        return $found;
    }
}
