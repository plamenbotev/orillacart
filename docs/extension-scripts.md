# Extension scripts

An extension adds to a shop without a PHP plugin: a payment or shipping provider, endpoints and pages,
records and fields, templates, handlers that run after the shop's events. It is written in the shop's own
script language, lives in the site's database, and runs in a sandbox with a budget and the permissions a
manager granted it (design 56). Write one on Settings › Extensions, or let an agent write it through the
assistant's `extension_*` tools; the same reference is the MCP resource `orillacart://extensions/reference`.
This is the catalogue: every declaration, function, filter and test in a line. The guide to writing one,
with what each handler receives and answers and how a script fails, is `docs/extension-scripts-guide.md`.
Editors: Settings › Extensions checks and completes as you type; VS Code does the same through
`tools/vscode`, a language server that asks the site.

## How a script reads
Statements: let x = …, x = …, if … { } elif … { } else { }, for item in list { }, for key, value in map { }, while … { }, return, break, continue, try { } catch e { }, fail unavailable("…") | refused("…") | error("…") (also as a value: x ?? fail refused("…")), assert test, "message". Expressions as in Twig: a.b, a["b"], a|filter(args), a is test, a ~ b, a ?? b, a ? b : c, a..b, in, not in, starts with, ends with, and, or, not. Values: null, booleans, numbers, money(amount), strings, [lists], { maps }, fn(args) { }. A statement ends at the end of its line; in a list or map written over several lines, the end of a line separates entries as a comma does.

A package is one or more `.ocs` files, compiled together, plus templates under `templates/`. Declarations
at the top level say what the extension adds; the functions inside them are what the shop calls. A top-level
`fn` is the extension's own, callable from its other functions and the console.

```
extension "payfast" {
  name: "PayFast"
  version: "1.0.0"
  needs: { orders: read, http: ["api.payfast.example"] }
}

settings {
  secret: secret { label: "API secret", required: true }
}

payment_gateway "payfast" {
  name: "Pay with PayFast"
  fn start(session, settings) {
    let answer = http.post("https://api.payfast.example/sessions", {
      json: { amount: session.amount, currency: session.currency, return_url: session.return_url },
      headers: { Authorization: "Bearer " ~ settings.secret },
    })
    if answer.status != 200 { fail unavailable("PayFast answered " ~ answer.status) }
    return payment.redirect(answer.json.url, answer.json.id)
  }
}
```

## Permissions

An extension declares in `needs` what it must reach: `orders: read`, `orders: write`, `products: read`,
`products: write`, `customers: read`, `http: ["host", …]` (the only hosts `http.*` may call, over https),
`templates: override` (it may replace the shop's own templates). A manager grants them when enabling it;
a function that needs one the extension was not granted is refused by the static check before, and by the
runtime after. Nothing else is reachable: no PHP, no files, no queries, no WordPress.

## Budgets and failures

A run has 100 000 steps, 2 seconds, 16 MB of values, 5 000 calls (every builtin, filter and shop function; the extension's own functions are steps), 5 HTTP calls, 5 emails and 50 queries. A run that
exceeds them is stopped; the script cannot catch that. `fail unavailable("…")` says a provider cannot be
reached (the shop tries later), `fail refused("…")` is an answer to show whoever asked, any other failure is
a problem the shop logs. Ten failures in a row stop the extension; a manager enables it again after reading
the problem on the Extensions screen.

## Declarations
- `extension "id" { name, version, needs: { orders: read, customers: [read, write], products: read, http: ["host"], templates: override } }`: what the extension is and what it asks for
- `settings { key: text|secret|boolean|number|choice { label, required, help, of } }`: the settings a manager fills in; read with settings.get(key)
- `payment_gateway "id" { name; settings {…}; fn start(session, settings); fn not_ready(settings, currency, decimals); fn reference(back); fn returned(reference, back, settings); fn notified(notification, settings); fn check(reference, transaction_id, settings); fn refund(request, settings) }`: a payment provider
- `shipping_method "id" { name; settings {…}; fn quote(shipment, settings); fn is_ready(settings); fn pickup_points(query, settings); fn create_shipment(request, settings); fn label(number, settings); fn cancel_shipment(number, settings); fn track(number, settings) }`: a shipping provider
- `cart_rule "id" { fn check(cart) }`: refusals of a cart, with cart.refuse() and cart.refuse_line()
- `order_fee "id" { fn fee(query) }`: a fee on the order, with fee.of()
- `price_adjuster "id" { fn adjust(query) }`: a product's price for a shopper
- `order_adjuster "id" { fn adjust(query) }`: amounts added to or taken off an order, with price.adjustment()
- `checkout_field "id" { label, kind, section, required, choices, help, longest; fn check(answer, context) }`: a field at checkout
- `order_action "id" { label; fn available(order); fn run(order) }`: a button on an order
- `order_column "id" { label; fn value(order) }, product_column "id" { label; fn value(item) }`: columns of the exports
- `order_filter "id" { label; fn find(text) }, product_filter "id" { label; fn find(text) }`: filters of the lists, marked with shop.orders.mark() / shop.products.mark(), and searches
- `home "id" { fn tiles(); fn needs_you() }`: tiles and lines on the Home screen, with home.tile() and home.line()
- `assistant_tool "name" { title, description, input, read_only, risk; fn run(arguments, call) }`: a tool of the assistant
- `product_block "id" { title, description; fn render(product, settings) }`: a block for product pages, its HTML cleaned
- `mailing_list "id" { name; fn subscribe(subscriber); fn unsubscribe(email); fn forget(email) }`: a list the newsletter signs up to
- `route METHOD "/path/{param}" { who: public|account|manager, tool: true|false|"name", title, description, input, risk } fn(request)`: a REST route under /orillacart/v1/x/<extension>/, answering a map or respond.json(), respond.redirect(), respond.error(), respond.html(); a browser sends the REST nonce on anything but GET, an app its key or token
- `page "/path" { template } fn(request)`: a storefront page of the extension's own, with a URL of the site's, rendered by the shop's Extension page block: place the block on a WordPress page (the editor, or the assistant's create_landing_page then publish_page with the content <!-- wp:orillacart/extension-page {"extension":"<id>","path":"/path"} /-->); the handler's map is the template's data
- `admin_page "/path" { title, template } fn(request)`: a screen of the extension's own in the Shop menu of wp-admin (design 60), for the shop manager: the entry is titled as declared and placed after the shop's groups, before System; the handler's map is the template's data; its scripts (asset()) call the extension's manager routes with wp.apiFetch
- `slot "product_after_details" { template } fn(context)`: a template printed in a storefront slot
- `template_data "storefront/product.html.twig" fn(context)`: what the extension adds to a template's context, under ext.<extension>
- `object "type" { fields: { name: kind { options } }, index: [field, …] }`: a kind of record of the extension's: objects.<type>.create/find/update/delete/query/count
- `fields on product|order|customer { name: kind { label, help, required, default, of, readonly, filter, column } }`: fields the extension adds to the shop's records: fields.get/set/of; edited in the "Extensions" section of the record's screen and shown in its REST view under extensions.<id>, in templates under ext.<id>.product|order|customer; a column of the export unless column: false; a product field with filter: true a facet of the search index, named by its label
- `on order_placed fn(event)`: a handler run in the background after one of the shop's events
- `job "name" fn(payload)`: a handler run in the background by jobs.push(name, payload)
- `every "hourly|twicedaily|daily|weekly" fn()`: a handler run on a schedule
- `fn name(args) { … }`: a function of the extension's own, callable from its other functions and the console
- `test "name" { http: { "POST https://…": { status, json } }, now: "2026-11-07 10:00", settings: { key: value } } fn() { … assert … }`: a test of the extension's own: run with http answered from the map, time standing at now, the settings given, jobs recorded, records rolled back

## Functions

Every call out of a script is one of these; `[needs …]` is the permission it takes.


### Without a namespace

- __("words"): the words in the language shown, as the extension's translations or the shop's give them; written as a string, values put in after (|replace)
- _n("one", "many", count): the form the count takes in the language shown
- _nx("one", "many", count, "context"): as _n(), with a context
- _x("words", "context"): as __(), told apart from the same words meaning something else by the context
- error(message, data?): the error value of a problem to log
- keys(map): its keys as a list
- language(): the locale words are shown in, as "de_DE"
- len(value): how many items a list or map has, or characters a string
- max(a, b, …) or max(list): the greatest
- min(a, b, …) or min(list): the least
- money(amount, currency?): a money value from a number or a decimal string, exact to four decimals; amounts are in the shop's currency unless the host says otherwise
- num(value): the value as a number
- refused(message, data?): the error value of an answer to show whoever asked
- str(value): the value as text
- unavailable(message, data?): the error value of a provider that cannot be reached; the shop tries again later
- values(map): its values as a list

### cart

- cart.refuse(message): the cart cannot be ordered as it is; the message says why
- cart.refuse_line(line_id, message): one line cannot be ordered as it is

### fee

- fee.of(label, amount, taxable?, tax_group_id?, as_line?): a fee added to the order

### fields

- fields.get(kind, id, field): the extension's field on a product, order or customer
- fields.of(kind, id): every field of the extension's on the record
- fields.set(kind, id, field, value): writes the extension's field on a product, order or customer; null clears it

### home

- home.line(text, url?, tone?): something that waits, on the Home screen; tone attention, warning or info
- home.tile(label, value, detail?, url?): a figure on the Home screen

### html

- html.escape(text): the text safe inside HTML

### http

- http.delete(url, { json?, body?, form?, headers?, timeout? }): a DELETE to a declared host; answers { status, headers, body, json } [needs http]
- http.get(url, { json?, body?, form?, headers?, timeout? }): a GET to a declared host; answers { status, headers, body, json } [needs http]
- http.patch(url, { json?, body?, form?, headers?, timeout? }): a PATCH to a declared host; answers { status, headers, body, json } [needs http]
- http.post(url, { json?, body?, form?, headers?, timeout? }): a POST to a declared host; answers { status, headers, body, json } [needs http]
- http.put(url, { json?, body?, form?, headers?, timeout? }): a PUT to a declared host; answers { status, headers, body, json } [needs http]

### jobs

- jobs.push(name, payload?, at?): runs the extension's job of that name later, in the background, with the payload; at a moment when given

### json

- json.parse(text): the value the JSON text holds
- json.text(value): the value as JSON

### objects

- objects.<type>.count({ where }): how many match
- objects.<type>.create(data): a new record of the declared kind; answers it with its id
- objects.<type>.delete(id): removes the record; whether there was one
- objects.<type>.find(id): the record, or null
- objects.<type>.query({ where, order_by, limit, after }): the records that match, on indexed fields; at most 200, the cursor `after` for the next page
- objects.<type>.update(id, data): changes the fields given; answers the record, or null

### payment

- payment.cancelled(reference): the customer gave up
- payment.completed(reference, transaction_id, amount?, currency?): the payment is made
- payment.disputed(reference, reason, transaction_id?): the customer disputes it with the provider
- payment.failed(reference, transaction_id?): the payment failed
- payment.held(reference, transaction_id?): the provider holds it for review
- payment.offline(): the order is placed and paid outside the shop (a transfer)
- payment.pending(reference): nothing is known yet; the shop asks again later
- payment.redirect(url, reference): send the customer to the provider; the reference names the payment from then on
- payment.refund_done(reference): the refund is made
- payment.refund_pending(reference): the provider will refund; the shop checks later
- payment.refunded(reference, refund_id, amount, currency?, transaction_id?, request?): the provider refunded this much

### price

- price.adjustment(label, amount, line?, reason?): an amount taken off (negative) or added to the order or one of its lines

### respond

- respond.error(status, message): refuse the request with a status of 400 to 599 and a message
- respond.html(template, data?, status?): one of the extension's templates rendered with the data; a route answers it as JSON { html }
- respond.json(data, status?): the data as JSON
- respond.redirect(url, status?): send the caller elsewhere

### settings

- settings.get(key): the value of one of the extension's settings, as the manager saved it; null when none

### shipping

- shipping.booked(number, tracking_url?, parcels?): the shipment the courier booked
- shipping.label(content, mime_type?): the label file's bytes
- shipping.pickup_point(id, name, address, hours?): an office or locker the customer may choose
- shipping.rate(code, name, price, delivery_days?, to_pickup_point?): one way to ship, offered at checkout
- shipping.tracking(status, description?): where a shipment is: booked, on_the_way, delivered or returned

### shop

- shop.customers.find(customer_id): the customer ({ id, email, first_name, last_name, registered_at, billing, shipping_address, group_ids, orders }), or null [needs customers:read]
- shop.email.send(to, subject, html, reply_to?): an email in the shop's layout, from its address; true when the mail system took it [needs email:send]
- shop.orders.create({ email, customer_id, billing, shipping_address, items: [{ product_id, quantity, option_ids, unit_price }], shipping_option, payment, paid, note, fields }): places the order as the shop does by hand, and answers the order map [needs orders:create]
- shop.orders.find(number): the order, or null [needs orders:read]
- shop.orders.is_marked(number, filter_id): whether the order is under the filter [needs orders:read]
- shop.orders.mark(number, filter_id): puts the order under the extension's order filter; false when there is no such order [needs orders:write]
- shop.orders.unmark(number, filter_id): takes the order out of the filter [needs orders:write]
- shop.products.find(product_id): the product as shoppers see it now ({ id, name, type, sku, price, regular_price, for_sale, available, on_backorder, variations[{ id, label, sku, price, regular_price, for_sale, available }] }), or null [needs products:read]
- shop.products.is_marked(product_id, filter_id): whether the product is under the filter [needs products:read]
- shop.products.mark(product_id, filter_id): puts the product under the extension's product filter [needs products:write]
- shop.products.unmark(product_id, filter_id): takes the product out of the filter [needs products:write]

### time

- time.add(moment, "30 days"): the moment later by that much ("-2 hours" earlier)
- time.diff(from, to): the seconds between
- time.format(moment, "d.m.Y"): the moment written with PHP's date format
- time.now(): this moment, as "2026-11-07T09:30:00+02:00"
- time.parse(text): a date or moment written any usual way, as time.now() writes it
- time.today(): the day, as "2026-11-07"

## Filters

`abs`, `amount`, `capitalize`, `default`, `first`, `join`, `json`, `keys`, `last`, `length`, `lower`, `map`, `merge`, `number`, `replace`, `reverse`, `round`, `slice`, `sort`, `split`, `title`, `trim`, `upper`, `values`

## Tests

`defined`, `empty`, `even`, `iterable`, `list`, `map`, `money`, `null`, `number`, `odd`, `string`
