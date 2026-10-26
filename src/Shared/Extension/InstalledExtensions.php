<?php

declare(strict_types=1);

namespace OrillaCart\Shared\Extension;

use OrillaCart\Extension\Assistant\AssistantTool;
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

/**
 * What the shop has through the extension API: its own payment gateways and those add-ons registered, and the
 * add-ons' shipping methods, price adjusters, cart rules, checkout fields, address checks, order fees, order actions export columns, and the filters and searches of the orders and products lists, each
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
     * @return list<AddressCheck>
     */
    public function addressChecks(): array;

    /**
     * @return list<OrderFee>
     */
    public function orderFees(): array;

    /**
     * @return list<MailingList>
     */
    public function mailingLists(): array;

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

    /**
     * @return list<OrderFilter>
     */
    public function orderFilters(): array;

    /**
     * @return list<FindsOrders>
     */
    public function orderSearches(): array;

    /**
     * @return list<ProductFilter>
     */
    public function productFilters(): array;

    /**
     * @return list<FindsProducts>
     */
    public function productSearches(): array;

    /**
     * @return list<TaxProvider>
     */
    public function taxProviders(): array;

    /**
     * @return list<EInvoiceSender>
     */
    public function eInvoiceSenders(): array;

    /**
     * @return list<OrderAdjuster>
     */
    public function orderAdjusters(): array;

    /**
     * Add-ons' tools for the AI assistant, by name.
     *
     * @return list<AssistantTool>
     */
    public function assistantTools(): array;
}
