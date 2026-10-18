<?php

declare(strict_types=1);

namespace OrillaCart\Extension;

use OrillaCart\Extension\Payment\PaymentGateway;
use OrillaCart\Extension\Shipping\ShippingMethod;

/**
 * Where add-ons register what they bring. The shop passes it once per request, when it first needs its payment
 * gateways or shipping methods, to the orillacart_extensions action:
 *
 *     add_action('orillacart_extensions', static function (OrillaCart\Extension\Registry $registry): void {
 *         $registry->paymentGateway(new MyGateway($registry->log()));
 *     });
 *
 * Only the OrillaCart\Extension namespace is for add-ons; the plugin's other classes change without notice.
 * Ids are unique across the shop: a second registration with a taken id is logged and left out.
 */
interface Registry
{
    /** The API version, Version::CURRENT of the running plugin. */
    public function version(): int;

    public function paymentGateway(PaymentGateway $gateway): void;

    public function shippingMethod(ShippingMethod $method): void;

    /** Writes to the shop's log, where the shop manager looks when something went wrong. */
    public function log(): Log;
}
