<?php

declare(strict_types=1);

namespace OrillaCart\Extension;

use OrillaCart\Extension\Cart\CartRule;
use OrillaCart\Extension\Checkout\CheckoutField;
use OrillaCart\Extension\Order\OrderAction;
use OrillaCart\Extension\Order\Orders;
use OrillaCart\Extension\Payment\PaymentGateway;
use OrillaCart\Extension\Pricing\PriceAdjuster;
use OrillaCart\Extension\Shipping\ShippingMethod;

/**
 * Where add-ons register what they bring. The shop passes it once per request, when it first needs something
 * add-ons register, to the orillacart_extensions action:
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

    /** Since version 2. */
    public function priceAdjuster(PriceAdjuster $adjuster): void;

    /** Since version 2. */
    public function cartRule(CartRule $rule): void;

    /** Since version 2. */
    public function checkoutField(CheckoutField $field): void;

    /** Since version 2. */
    public function orderAction(OrderAction $action): void;

    /** Reads the shop's orders. Since version 2. */
    public function orders(): Orders;

    /** Writes to the shop's log, where the shop manager looks when something went wrong. */
    public function log(): Log;
}
