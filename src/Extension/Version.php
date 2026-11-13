<?php

declare(strict_types=1);

namespace OrillaCart\Extension;

/**
 * The version of the extension API. It changes only by adding: a new capability, method on a value object or
 * optional argument. An add-on can check it before registering what needs a newer API:
 *
 *     if ($registry->version() >= 2) { ... }
 */
final class Version
{
    /**
     * 1: payment gateways, shipping methods. 2: price adjusters, cart rules, checkout fields, order actions,
     * orders. 3: gateways naming the payment of a notification (NamesNotifiedPayments), checking payments and
     * refunds at the provider (ChecksPayments, ChecksRefunds). 4: columns of the orders export and the products
     * file (OrderColumn, ProductColumn, ImportsProductColumn). 5: fees on orders (OrderFee), the handling of a
     * PaymentBreakdown, an order's fees in its OrderSummary, checking addresses (AddressCheck), each parcel's number
     * of a BookedShipment. 6: mailing lists for the newsletter's sign-ups (MailingList). 7: the answers to the shop's product forms on
     * each OrderLine. 8: filters and searches of the Orders screen and the products list (OrderFilter, FindsOrders,
     * ProductFilter, FindsProducts), and Orders::mark(). 9: tax services (TaxProvider), changes to
     * order prices at checkout (OrderAdjuster) and on orders on hold (OrderPrices). 10: sending e-invoices on
     * (EInvoiceSender). 11: gateways naming the account or mode a payment is made in (NamesAccounts). 12: mailing
     * lists that delete a contact (ForgetsSubscribers), and the orillacart_newsletter_unsubscribed action. 13: a
     * shipment the courier has booked but not collected yet (Tracking::BOOKED). 14: gateways and shipping methods
     * whose settings the shop manager can test (TestsSettings). 15: tools for the shop's AI assistant
     * (Assistant\AssistantTool). 16: blocks for product pages, cards and landing pages (Storefront\ProductBlock). 17: products translated by Polylang or
     * WPML (Catalog\ProductLanguages). 18: lines an add-on keeps in the cart, sold as order lines of their type
     * (Cart\CartLineType), and each OrderLine's type. 19: the customer's email in a PaymentSession. 20: figures and "Needs you" lines on the Home screen
     * (Home\HomeContributor). 21: the checkout answers in an AdjustmentQuery, and each OrderLine's adjustments. 22: reading products
     * (Products::find) and customers (Customers), sending email (Notifications\Emails), placing orders
     * (Order\CreatesOrders). 23: gateways whose notifications the shop confirms with them before taking the money
     * (Payment\ConfirmsNotifications), when an unpaid order's payment must be made by (payBy on StartPayment and
     * PaymentSession), the settings a secret goes with (Setting::$goesWith), and the risk of a tool that changes
     * something else in the shop (AssistantTool::CHANGES). 24: blocks for the whole site (Storefront\SiteBlock),
     * folders of storefront templates rendered in the sandbox (Storefront\UntrustedTemplateFolders), filters for
     * every storefront template (Storefront\TemplateFilter), and rendering the storefront's templates
     * (Storefront\Templates). 25: the shop's own templates' names and sources (Templates::names, Templates::original).
     * 26: making and changing products, variations, attribute sets and categories (Registry::productWriting,
     * attributeSets, categories), finding products (Products::search), and ProductSummary's published and attributes.
     * 27: making and finding customers (Customers::create, Customers::search, Customer\NewCustomer), and what an order
     * would cost and how it could ship before it is placed (CreatesOrders::quote, Order\OrderQuote, Order\ShippingChoice).
     * 28: site blocks that hold other blocks, know where they are and have an editing screen of their own
     * (Storefront\PlacedSiteBlock, BlockPlace), blocks' image, link, color, product, category, rich_text and list
     * settings (Setting::BLOCK_TYPES, Setting::$fields), and block patterns (Storefront\BlockPattern).
     */
    public const int CURRENT = 28;
}
