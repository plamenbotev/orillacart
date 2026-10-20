<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Payment;

use OrillaCart\Extension\Cart\CartRule;
use OrillaCart\Extension\Catalog\ProductColumn;
use OrillaCart\Extension\Checkout\AddressCheck;
use OrillaCart\Extension\Checkout\CheckoutField;
use OrillaCart\Extension\Checkout\OrderFee;
use OrillaCart\Extension\Marketing\MailingList;
use OrillaCart\Extension\Order\OrderAction;
use OrillaCart\Extension\Order\OrderColumn;
use OrillaCart\Extension\Payment\PaymentGateway;
use OrillaCart\Extension\Pricing\PriceAdjuster;
use OrillaCart\Extension\Shipping\ShippingMethod;
use OrillaCart\Shared\Extension\InstalledExtensions;

/**
 * What a test gives the shop, as if built in or registered by add-ons. Tests add adjusters, rules, fields, fees,
 * actions and columns as they go.
 */
final class FixedExtensions implements InstalledExtensions
{
    /** @var list<PriceAdjuster> */
    public array $adjusters = [];
    /** @var list<CartRule> */
    public array $cartRules = [];
    /** @var list<CheckoutField> */
    public array $fields = [];
    /** @var list<AddressCheck> */
    public array $addressChecks = [];
    /** @var list<OrderFee> */
    public array $fees = [];
    /** @var list<OrderAction> */
    public array $actions = [];
    /** @var list<MailingList> */
    public array $mailingLists = [];
    /** @var list<OrderColumn> */
    public array $orderColumns = [];
    /** @var list<ProductColumn> */
    public array $productColumns = [];

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

    public function addressChecks(): array
    {
        return $this->addressChecks;
    }

    public function mailingLists(): array
    {
        return $this->mailingLists;
    }

    public function orderFees(): array
    {
        return $this->fees;
    }

    public function orderActions(): array
    {
        return $this->actions;
    }

    public function orderColumns(): array
    {
        return $this->orderColumns;
    }

    public function productColumns(): array
    {
        return $this->productColumns;
    }
}
