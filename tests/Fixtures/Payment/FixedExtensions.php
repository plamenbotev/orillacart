<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Payment;

use OrillaCart\Extension\Payment\PaymentGateway;
use OrillaCart\Shared\Extension\InstalledExtensions;

/**
 * The gateways a test gives the shop, as if built in or registered by add-ons.
 */
final readonly class FixedExtensions implements InstalledExtensions
{
    /**
     * @param list<PaymentGateway> $gateways
     */
    public function __construct(private array $gateways) {}

    public function paymentGateways(): array
    {
        return $this->gateways;
    }
}
