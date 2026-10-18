<?php

declare(strict_types=1);

namespace OrillaCart\Shared\Extension;

use OrillaCart\Extension\Payment\PaymentGateway;
use OrillaCart\Extension\Shipping\ShippingMethod;

/**
 * What the shop has through the extension API: its own payment gateways and those add-ons registered, and the
 * add-ons' shipping methods.
 */
interface InstalledExtensions
{
    /**
     * @return list<PaymentGateway> the built-in ones first, in their order, then the add-ons'
     */
    public function paymentGateways(): array;

    /**
     * @return list<ShippingMethod>
     */
    public function shippingMethods(): array;
}
