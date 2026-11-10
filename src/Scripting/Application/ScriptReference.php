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
        'page "/path" { template } fn(request)' => 'a storefront page of the extension\'s own, with a URL of the site\'s, rendered by the shop\'s Extension page block: place the block on a WordPress page (the editor, or the assistant\'s create_landing_page then publish_page with the content <!-- wp:orillacart/extension-page {"extension":"<id>","path":"/path"} /-->); the handler\'s map is the template\'s data',
        'admin_page "/path" { title, template } fn(request)' => 'a screen of the extension\'s own in the Shop menu of wp-admin (design 60), for the shop manager: the entry is titled as declared and placed after the shop\'s groups, before System; the handler\'s map is the template\'s data; its scripts (asset()) call the extension\'s manager routes with wp.apiFetch',
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

    /**
     * What each handler receives and answers, and the maps those arguments are made of: the written guide
     * explains them, this is the list a model can look up.
     */
    public const array HANDLERS = [
        'order_adjuster.adjust(query)' => 'query: { lines[{index, product_id, variation_id, name, quantity, net}], currency, customer_id, email, order_number, fields }; fields are the answers so far to checkout\'s questions by field id as text (a ticked box "1", an unticked one absent; the checkout prices again when a box or choice changes; an edited order brings its own). Answers a list of price.adjustment(label, amount, line?, reason?), amount below zero to take off. The order keeps each as an adjustment with this adjuster\'s id as source.',
        'price_adjuster.adjust(query)' => 'query: { product_id, variation_id, quantity, customer_id, price, regular_price, currency }. Answers the new price (money or text) or null.',
        'order_fee.fee(query)' => 'query: { items[{product_id, variation_id, name, quantity, unit_price, total}], subtotal, shipping, shipping_option, payment_method, country, customer_id, currency }. Answers fee.of(label, amount, taxable?, tax_group_id?, as_line?) or null.',
        'cart_rule.check(cart)' => 'cart: { customer_id, currency, lines[{id, product_id, variation_id, sku, name, quantity, unit_price}] }. Answers a list of cart.refuse(message) and cart.refuse_line(line_id, message), empty or null for none.',
        'checkout_field.check(answer, context)' => 'answer: the text given (a ticked box "1"); context: { email, billing_country, shipping_country (null when nothing ships), customer_id }. Answers null or true to accept, or the message to show.',
        'order_action.available(order) / run(order)' => 'order: the order map. available answers a boolean; run does the work and answers the message to show.',
        'order_column.value(order) / product_column.value(item)' => 'order: the order map; item: { product_id, variation_id, sku, name, … }. Answers the text of the cell.',
        'order_filter.find(text) / product_filter.find(text)' => 'text: what was searched. Answers a list of ids under the filter, [] for none.',
        'route … fn(request)' => 'request: { method, path, params, query, body (the JSON body as a map, or null), headers (lower-case names), user: { id, logged_in, manager } }. Answers a map or list sent as JSON, or respond.json(data, status?), respond.error(status, message), respond.redirect(url, status?), respond.html(template, data?, status?). A template calls one with window.orillacart.api(method, "/x/<extension>/<path>", body), which carries the REST nonce; api_url(path) and api_nonce() give the URL and the token in a template.',
        'page … fn(request)' => 'request: as a route\'s, with the page\'s query string. Answers the template\'s data. The page shows where the Extension page block is placed (see the page declaration): the assistant places it with create_landing_page and publish_page.',
        'admin_page … fn(request)' => 'request: as a route\'s, with the screen\'s query string, as the manager. Answers the template\'s data; fail refused(message) shows the message instead of the screen.',
        'slot … fn(context)' => 'context: { slot, arguments }; see slots for what each passes. Answers a map added to the template\'s context.',
        'template_data … fn(context)' => 'context: the shop template\'s own context. Answers a map, under ext.<extension> in the template.',
        'on <event> fn(event)' => 'event: { event, arguments, order | product | cart }; see events for the arguments.',
        'job … fn(payload)' => 'payload: what jobs.push gave.',
        'assistant_tool.run(arguments, call)' => 'arguments: as the tool\'s input schema; call: { user_id, key_name }. Answers the tool\'s result (a map or text).',
        'payment_gateway.start(session) / handle(notification) / …' => 'session: { payment_id, order_number, amount, currency, return_url, cancel_url, notification_url, items[{name, sku, quantity, unit_amount}], breakdown, email, … }; notification: { headers, body, json, query }; see the guide\'s §8 for each gateway and courier handler.',
        'the product map' => 'shop.products.find(id): { id, name, type, sku, price (money), regular_price, for_sale, available, on_backorder, variations[{ id, label, sku, price, regular_price, for_sale, available }] }; product_block.render(product, settings): product is { id, name, url, sku, price, regular_price, for_sale, available, rating, reviews, … }, the product shown; product_column.value(item) and product_filter rows: { product_id, variation_id, name, sku, price }. A product field of the extension\'s is read with fields.get("product", id, name).',
        'the customer map' => 'shop.customers.find(id): { id, email, first_name, last_name, registered_at, billing, shipping_address, group_ids, orders }',
        'the order map' => '{ number, status, placed_at, paid_at, email, customer_id, billing{first_name, last_name, street, city, postcode, country, state, phone}, shipping_address, lines[{product_id, variation_id, sku, name, quantity, unit_price, total, tax, personalisation, type, add_on_type, adjustments[{kind, source, label, amount, scope, reason}]}], subtotal, discount, shipping_total, tax, total, refunded, currency, payment, transaction_id, shipping, note, fields, fees }: as shop.orders.find, order_action, order_column and the events\' lookups give it. A line\'s adjustments are the changes to its price as the order keeps them: kind coupon | automatic | manual | add_on | adjuster, source the coupon\'s code or the extension\'s or adjuster\'s id, amount money (below zero for a discount), scope line or order (a share of a change to the whole order).',
    ];

    /** What an extension may ask for under `needs`, and what each grants. */
    public const array PERMISSIONS = [
        'orders: read' => 'shop.orders.find, is_marked; the order map in handlers',
        'orders: write' => 'shop.orders.mark and unmark',
        'orders: create' => 'shop.orders.create: places real orders, takes stock, emails the customer; its own permission, granted on purpose',
        'products: read' => 'shop.products.find, is_marked',
        'products: write' => 'shop.products.mark and unmark',
        'customers: read' => 'shop.customers.find',
        'email: send' => 'shop.email.send: email in the shop\'s layout, from its address, five a run',
        'http: ["host.example", …]' => 'http.* calls to those hosts over HTTPS; the manager grants each host',
        'templates: override' => 'a file under templates/storefront/ or templates/emails/ in the package replaces the shop\'s template of that name',
        'assets: serve' => 'the package\'s files under assets/ (.js, .css, .svg) served to browsers, printed by asset() in its templates',
    ];

    /** The kinds a declaration may use for its settings and fields. */
    public const array KINDS = [
        'settings' => 'text, secret, boolean, number, choice { of: list or value → label map }; each takes label, required, help',
        'object fields' => 'text { longest }, number, money, boolean, date, datetime, choice { of }, list, map, product, order, customer, object; each takes required and default; index: [fields] names what query and order_by may use',
        'fields on product|order|customer' => 'the same kinds plus the options label, help, required, default, readonly, filter (a product field as a search facet), column (false to leave it out of exports)',
        'checkout_field' => 'kind text | textarea | checkbox | choice { choices }; section billing | shipping | order; label, required, help, longest',
    ];

    /** The budgets one handler call has. */
    public const array LIMITS = [
        'steps' => 100000,
        'seconds' => 2,
        'memory_bytes' => 16777216,
        'calls' => 5000,
        'http_calls' => 5,
        'queries' => 50,
        'emails' => 5,
        'query_records' => 200,
        'errors_in_a_row_before_stopped' => 10,
        'route_requests_per_minute' => 120,
    ];

    public function __construct(private HostCatalog $catalog, private ?ScriptGuide $guide = null) {}

    /**
     * @return array{language: string, declarations: array<string, string>, functions: list<array{name: string, summary: string, arguments: array{int, ?int}, needs: ?string}>, filters: list<string>, tests: list<string>, keywords: list<string>, events: array<string, string>, slots: array<string, string>, handlers: array<string, string>, permissions: array<string, string>, kinds: array<string, string>, limits: array<string, int>, template_sandbox: array<string, list<string>>, templates: array<string, string>}
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
            'handlers' => self::HANDLERS,
            'permissions' => self::PERMISSIONS,
            'kinds' => self::KINDS,
            'limits' => self::LIMITS,
            // What an extension's templates (slots, pages, overrides) may use.
            'template_sandbox' => $this->guide?->templateSandbox() ?? [],
            // The shop's storefront templates with what each gets, for template_data and overrides.
            'templates' => $this->guide?->templateNotes() ?? [],
            'slots' => self::SLOTS,
        ];
    }
}
