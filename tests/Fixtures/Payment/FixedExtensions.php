<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Payment;

use OrillaCart\Extension\Cart\CartRule;
use OrillaCart\Extension\Checkout\CheckoutField;
use OrillaCart\Extension\Order\OrderAction;
use OrillaCart\Extension\Payment\PaymentGateway;
use OrillaCart\Extension\Pricing\PriceAdjuster;
use OrillaCart\Extension\Shipping\ShippingMethod;
use OrillaCart\Shared\Extension\InstalledExtensions;

/**
 * What a test gives the shop, as if built in or registered by add-ons. Tests add adjusters, rules, fields and
 * actions as they go.
 */
final class FixedExtensions implements InstalledExtensions
{
    /** @var list<PriceAdjuster> */
    public array $adjusters = [];
    /** @var list<CartRule> */
    public array $cartRules = [];
    /** @var list<CheckoutField> */
    public array $fields = [];
    /** @var list<OrderAction> */
    public array $actions = [];

    /**
     * @param list<PaymentGateway> $gateways
     * @param list<ShippingMethod> $methods
     */
    public function __construct(private readonly array $gateways = [], private readonly array $methods = []) {}

    public function paymentGateways(): array
    {
        return $this->gateways;
    }

    public function shippingMethods(): array
    {
        return $this->methods;
    }

    public function priceAdjusters(): array
    {
        return $this->adjusters;
    }

    public function cartRules(): array
    {
        return $this->cartRules;
    }

    public function checkoutFields(): array
    {
        return $this->fields;
    }

    public function orderActions(): array
    {
        return $this->actions;
    }
}
