<?php

declare(strict_types=1);

namespace OrillaCart\Scripting\Application;

use OrillaCart\Scripting\Application\Dto\HostFunction;
use OrillaCart\Scripting\Domain\Parser;

/**
 * The language as a reference for whoever writes a script, a person or an agent: how it reads, the declarations
 * the shop takes, every host function with what it takes and needs, the filters and the tests. One source for
 * the REST route, the MCP resource and the generated docs.
 */
final readonly class ScriptReference
{
    public const array DECLARATIONS = [
        'extension "id" { name, version, needs: { orders: read, customers: [read, write], products: read, http: ["host"], templates: override } }' => 'what the extension is and what it asks for',
        'settings { key: text|secret|boolean|number|choice { label, required, help, of } }' => 'the settings a manager fills in; read with settings.get(key)',
        'payment_gateway "id" { name; settings {…}; fn start(session, settings); fn not_ready(settings, currency, decimals); fn reference(back); fn returned(reference, back, settings); fn notified(notification, settings); fn check(reference, transaction_id, settings); fn refund(request, settings) }' => 'a payment provider',
        'shipping_method "id" { name; settings {…}; fn quote(shipment, settings); fn is_ready(settings); fn pickup_points(query, settings); fn create_shipment(request, settings); fn label(number, settings); fn cancel_shipment(number, settings); fn track(number, settings) }' => 'a shipping provider',
        'cart_rule "id" { fn check(cart) }' => 'refusals of a cart, with cart.refuse() and cart.refuse_line()',
        'order_fee "id" { fn fee(query) }' => 'a fee on the order, with fee.of()',
        'price_adjuster "id" { fn adjust(query) }' => 'a product\'s price for a shopper',
        'order_adjuster "id" { fn adjust(query) }' => 'amounts added to or taken off an order, with price.adjustment()',
        'checkout_field "id" { label, kind, section, required, choices, help, longest; fn check(answer, context) }' => 'a field at checkout',
        'order_action "id" { label; fn available(order); fn run(order) }' => 'a button on an order',
        'order_column "id" { label; fn value(order) }, product_column "id" { label; fn value(item) }' => 'columns of the exports',
        'order_filter "id" { label; fn find(text) }, product_filter "id" { label; fn find(text) }' => 'filters of the lists, marked with shop.orders.mark() / shop.products.mark(), and searches',
        'home "id" { fn tiles(); fn needs_you() }' => 'tiles and lines on the Home screen, with home.tile() and home.line()',
        'assistant_tool "name" { title, description, input, read_only, risk; fn run(arguments, call) }' => 'a tool of the assistant',
        'product_block "id" { title, description; fn render(product, settings) }' => 'a block for product pages, its HTML cleaned',
        'mailing_list "id" { name; fn subscribe(subscriber); fn unsubscribe(email); fn forget(email) }' => 'a list the newsletter signs up to',
        'route METHOD "/path/{param}" { who: public|account|manager, tool: true|false|"name", title, description, input, risk } fn(request)' => 'a REST route under /orillacart/v1/x/<extension>/, answering a map or respond.json(), respond.redirect(), respond.error(), respond.html(); a browser sends the REST nonce on anything but GET, an app its key or token',
        'page "/path" { template } fn(request)' => 'a page rendered on the Extension page block, the handler\'s map as the template\'s data',
        'slot "product_after_details" { template } fn(context)' => 'a template printed in a storefront slot',
        'template_data "storefront/product.html.twig" fn(context)' => 'what the extension adds to a template\'s context, under ext.<extension>',
        'object "type" { fields: { name: kind { options } }, index: [field, …] }' => 'a kind of record of the extension\'s: objects.<type>.create/find/update/delete/query/count',
        'fields on product|order|customer { name: kind { label, help, required, default, of, readonly, filter, column } }' => 'fields the extension adds to the shop\'s records: fields.get/set/of; edited in the "Extensions" section of the record\'s screen and shown in its REST view under extensions.<id>, in templates under ext.<id>.product|order|customer; a column of the export unless column: false; a product field with filter: true a facet of the search index, named by its label',
        'on order_placed fn(event)' => 'a handler run in the background after one of the shop\'s events',
        'job "name" fn(payload)' => 'a handler run in the background by jobs.push(name, payload)',
        'every "hourly|twicedaily|daily|weekly" fn()' => 'a handler run on a schedule',
        'fn name(args) { … }' => 'a function of the extension\'s own, callable from its other functions and the console',
        'test "name" { http: { "POST https://…": { status, json } }, now: "2026-11-07 10:00", settings: { key: value } } fn() { … assert … }' => 'a test of the extension\'s own: run with http answered from the map, time standing at now, the settings given, jobs recorded, records rolled back',
    ];

    /**
     * What each of the shop's events hands `on <event> fn(event)`: `event.arguments` in this order, and the first
     * under its own name (`event.order`, `event.product`, `event.cart`).
     */
    public const array EVENTS = [
        'order_placed' => 'arguments: [order number]; event.order is the number. A customer placed an order.',
        'order_paid' => 'arguments: [order number]; event.order. The money came, however it came.',
        'order_changed' => 'arguments: [order number]; event.order. The shop changed its items, its customer\'s details or whose order it is.',
        'order_status_changed' => 'arguments: [order number, new status, previous status]; event.order.',
        'order_refunded' => 'arguments: [order number, amount (decimal text), whether the order is now refunded in full (boolean)]; event.order.',
        'order_note_added' => 'arguments: [order number, the note, whether it is for the customer (boolean)]; event.order.',
        'order_needs_attention' => 'arguments: [order number, the reason]; event.order.',
        'order_deleted' => 'arguments: [order number]; event.order. A cancelled or failed order, never paid, was deleted.',
        'product_changed' => 'arguments: [product id]; event.product. Its price, options, variations or other commerce details changed.',
        'stock_changed' => 'arguments: [item such as "product:12" or "variation:34", how many could be sold before, how many after (null: stock did not limit it)]; event.product is the item.',
        'cart_item_added' => 'arguments: [product id, variation id or null, how many were added, customer id or null for a guest]; event.cart is the product id.',
        'cart_item_changed' => 'arguments: [product id, variation id or null, new quantity, previous quantity, customer id or null]; event.cart is the product id.',
        'cart_item_removed' => 'arguments: [product id, variation id or null, the quantity it had, customer id or null]; event.cart is the product id.',
        'catalog_items_removed' => 'arguments: [product ids, variation ids, attribute option ids] (lists).',
        'price_rules_changed' => 'arguments: [the scopes before and after the change: list of {groups, products, categories, brands}, each a list of ids, empty for all].',
        'tax_rates_changed' => 'arguments: [tax group id].',
        'settings_changed' => 'arguments: [the settings now, the settings before] (maps of the shop\'s settings).',
    ];

    /** What the storefront passes each slot, as `context.arguments` of `slot "<name>" fn(context)`. */
    public const array SLOTS = [
        'product_before_form' => '[product id]: the product shown',
        'product_after_form' => '[product id]',
        'product_after_details' => '[product id]',
        'cart_before' => '[] (read the cart through its page)',
        'cart_after_totals' => '[]',
        'cart_after' => '[]',
        'checkout_before_details' => '[]',
        'checkout_after_details' => '[]',
        'checkout_after_billing' => '[]',
        'checkout_after_shipping' => '[]',
        'checkout_before_place_order' => '[]',
        'account_after' => '[section ("orders", "order", "downloads", "addresses", "emails", "wishlist", "tax-exemptions"), customer {id, email, first_name, last_name}]: the customer viewing their account; a who: account route of the extension can serve them',
        'receipt_after' => '[order number]: the order the customer is looking at',
        'email_after_order' => '[order number, for_shop (boolean: the shop\'s copy of the email, not the customer\'s)]',
    ];

    public function __construct(private HostCatalog $catalog) {}

    /**
     * @return array{language: string, declarations: array<string, string>, functions: list<array{name: string, summary: string, arguments: array{int, ?int}, needs: ?string}>, filters: list<string>, tests: list<string>, keywords: list<string>, events: array<string, string>, slots: array<string, string>}
     */
    public function reference(): array
    {
        return [
            'language' => 'Statements: let x = …, x = …, if … { } elif … { } else { }, for item in list { }, for key, value in map { }, while … { }, return, break, continue, try { } catch e { }, fail unavailable("…") | refused("…") | error("…") (also as a value: x ?? fail refused("…")), assert test, "message". Expressions as in Twig: a.b, a["b"], a|filter(args), a is test, a ~ b, a ?? b, a ? b : c, a..b, in, not in, starts with, ends with, and, or, not. Values: null, booleans, numbers, money(amount), strings, [lists], { maps }, fn(args) { }. A statement ends at the end of its line; in a list or map written over several lines, the end of a line separates entries as a comma does.',
            'declarations' => self::DECLARATIONS,
            'functions' => array_values(array_map(static fn(HostFunction $function): array => [
                'name' => $function->name,
                'summary' => $function->summary,
                'arguments' => [$function->leastArguments, $function->mostArguments],
                'needs' => $function->needs,
            ], $this->catalog->all())),
            ...Scripts::vocabulary(),
            'keywords' => Parser::KEYWORDS,
            'events' => self::EVENTS,
            'slots' => self::SLOTS,
        ];
    }
}
