<?php

declare(strict_types=1);

namespace OrillaCart\Extension;

use OrillaCart\Extension\Assistant\AssistantTool;
use OrillaCart\Extension\Cart\CartRule;
use OrillaCart\Extension\Catalog\FindsProducts;
use OrillaCart\Extension\Catalog\ProductColumn;
use OrillaCart\Extension\Catalog\ProductFilter;
use OrillaCart\Extension\Catalog\ProductLanguages;
use OrillaCart\Extension\Catalog\Products;
use OrillaCart\Extension\Checkout\AddressCheck;
use OrillaCart\Extension\Checkout\CheckoutField;
use OrillaCart\Extension\Checkout\OrderFee;
use OrillaCart\Extension\Invoice\EInvoiceSender;
use OrillaCart\Extension\Marketing\MailingList;
use OrillaCart\Extension\Order\FindsOrders;
use OrillaCart\Extension\Order\OrderAction;
use OrillaCart\Extension\Order\OrderColumn;
use OrillaCart\Extension\Order\OrderFilter;
use OrillaCart\Extension\Order\OrderPrices;
use OrillaCart\Extension\Order\Orders;
use OrillaCart\Extension\Payment\PaymentGateway;
use OrillaCart\Extension\Pricing\OrderAdjuster;
use OrillaCart\Extension\Pricing\PriceAdjuster;
use OrillaCart\Extension\Shipping\ShippingMethod;
use OrillaCart\Extension\Storefront\ProductBlock;
use OrillaCart\Extension\Tax\TaxProvider;

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

    /** Changes an order's prices as checkout prices it, after the coupons. Since version 9. */
    public function orderAdjuster(OrderAdjuster $adjuster): void;

    /** Since version 2. */
    public function cartRule(CartRule $rule): void;

    /** Since version 2. */
    public function checkoutField(CheckoutField $field): void;

    /** Checks the addresses customers enter at checkout, suggesting corrections. Since version 5. */
    public function addressCheck(AddressCheck $check): void;

    /** A fee checkout adds to orders. Since version 5. */
    public function orderFee(OrderFee $fee): void;

    /** Since version 2. */
    public function orderAction(OrderAction $action): void;

    /** A column of the orders export. Since version 4. */
    public function orderColumn(OrderColumn $column): void;

    /** A column of the products file, exported and, for an ImportsProductColumn, imported. Since version 4. */
    public function productColumn(ProductColumn $column): void;

    /** A mailing list the shop's newsletter sign-ups may go to. Since version 6. */
    public function mailingList(MailingList $list): void;

    /** A filter of the Orders screen. Since version 8. */
    public function orderFilter(OrderFilter $filter): void;

    /** Finds orders for the Orders screen's search box by the add-on's own text. Since version 8. */
    public function orderSearch(FindsOrders $search): void;

    /** A filter of the products list. Since version 8. */
    public function productFilter(ProductFilter $filter): void;

    /** Finds products for the products list's search box by the add-on's own text. Since version 8. */
    public function productSearch(FindsProducts $search): void;

    /** A tax service the shop manager may choose to work out and record the taxes. Since version 9. */
    public function taxProvider(TaxProvider $provider): void;

    /** Sends each e-invoice on once it is issued, as through a Peppol access point. Since version 10. */
    public function eInvoiceSender(EInvoiceSender $sender): void;

    /** Reads the shop's orders, and marks them for order filters (since version 8). Since version 2. */
    public function orders(): Orders;

    /** Marks the shop's products for product filters. Since version 8. */
    public function products(): Products;

    /** Which post is the product where Polylang or WPML translates products. Since version 17. */
    public function productLanguages(): ProductLanguages;

    /** Changes the prices of orders the shop keeps on hold. Since version 9. */
    public function orderPrices(): OrderPrices;

    /** A tool for the shop's AI assistant (MCP). Since version 15. */
    public function assistantTool(AssistantTool $tool): void;

    /** A block for product pages, product cards and landing pages. Since version 16. */
    public function productBlock(ProductBlock $block): void;

    /** Writes to the shop's log, where the shop manager looks when something went wrong. */
    public function log(): Log;
}
