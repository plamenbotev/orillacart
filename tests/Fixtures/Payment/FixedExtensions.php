<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Payment;

use OrillaCart\Extension\Cart\CartRule;
use OrillaCart\Extension\Catalog\FindsProducts;
use OrillaCart\Extension\Catalog\ProductColumn;
use OrillaCart\Extension\Catalog\ProductFilter;
use OrillaCart\Extension\Checkout\AddressCheck;
use OrillaCart\Extension\Checkout\CheckoutField;
use OrillaCart\Extension\Checkout\OrderFee;
use OrillaCart\Extension\Invoice\EInvoiceSender;
use OrillaCart\Extension\Marketing\MailingList;
use OrillaCart\Extension\Order\FindsOrders;
use OrillaCart\Extension\Order\OrderAction;
use OrillaCart\Extension\Order\OrderColumn;
use OrillaCart\Extension\Order\OrderFilter;
use OrillaCart\Extension\Payment\PaymentGateway;
use OrillaCart\Extension\Pricing\OrderAdjuster;
use OrillaCart\Extension\Pricing\PriceAdjuster;
use OrillaCart\Extension\Shipping\ShippingMethod;
use OrillaCart\Extension\Tax\TaxProvider;
use OrillaCart\Shared\Extension\InstalledExtensions;

/**
 * What a test gives the shop, as if built in or registered by add-ons. Tests add adjusters, rules, fields, fees,
 * actions, columns, filters and searches as they go.
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
    /** @var list<OrderFilter> */
    public array $orderFilters = [];
    /** @var list<FindsOrders> */
    public array $orderSearches = [];
    /** @var list<ProductFilter> */
    public array $productFilters = [];
    /** @var list<FindsProducts> */
    public array $productSearches = [];
    /** @var list<TaxProvider> */
    public array $taxProviders = [];
    /** @var list<EInvoiceSender> */
    public array $eInvoiceSenders = [];
    /** @var list<OrderAdjuster> */
    public array $orderAdjusters = [];

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

    public function orderFilters(): array
    {
        return $this->orderFilters;
    }

    public function orderSearches(): array
    {
        return $this->orderSearches;
    }

    public function productFilters(): array
    {
        return $this->productFilters;
    }

    public function productSearches(): array
    {
        return $this->productSearches;
    }

    public function eInvoiceSenders(): array
    {
        return $this->eInvoiceSenders;
    }

    public function taxProviders(): array
    {
        return $this->taxProviders;
    }

    public function orderAdjusters(): array
    {
        return $this->orderAdjusters;
    }

    public function assistantTools(): array
    {
        return [];
    }

    public function productBlocks(): array
    {
        return [];
    }
}
