<?php

declare(strict_types=1);

namespace OrillaCart\Shared\Extension;

use OrillaCart\Extension\Cart\CartRule;
use OrillaCart\Extension\Catalog\ProductColumn;
use OrillaCart\Extension\Checkout\CheckoutField;
use OrillaCart\Extension\Checkout\OrderFee;
use OrillaCart\Extension\Order\OrderAction;
use OrillaCart\Extension\Order\OrderColumn;
use OrillaCart\Extension\Payment\PaymentGateway;
use OrillaCart\Extension\Pricing\PriceAdjuster;
use OrillaCart\Extension\Shipping\ShippingMethod;

/**
 * What the shop has through the extension API: its own payment gateways and those add-ons registered, and the
 * add-ons' shipping methods, price adjusters, cart rules, checkout fields, order fees, order actions and export columns, each
 * in the order they were registered.
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

    /**
     * @return list<PriceAdjuster>
     */
    public function priceAdjusters(): array;

    /**
     * @return list<CartRule>
     */
    public function cartRules(): array;

    /**
     * @return list<CheckoutField>
     */
    public function checkoutFields(): array;

    /**
     * @return list<OrderFee>
     */
    public function orderFees(): array;

    /**
     * @return list<OrderAction>
     */
    public function orderActions(): array;

    /**
     * @return list<OrderColumn>
     */
    public function orderColumns(): array;

    /**
     * @return list<ProductColumn>
     */
    public function productColumns(): array;
}
