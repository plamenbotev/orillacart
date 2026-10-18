<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Payment;

use OrillaCart\Extension\Payment\PaymentGateway;
use OrillaCart\Extension\Shipping\ShippingMethod;
use OrillaCart\Shared\Extension\InstalledExtensions;

/**
 * The gateways and shipping methods a test gives the shop, as if built in or registered by add-ons.
 */
final readonly class FixedExtensions implements InstalledExtensions
{
    /**
     * @param list<PaymentGateway> $gateways
     * @param list<ShippingMethod> $methods
     */
    public function __construct(private array $gateways = [], private array $methods = []) {}

    public function paymentGateways(): array
    {
        return $this->gateways;
    }

    public function shippingMethods(): array
    {
        return $this->methods;
    }
}
