# 30 · Add-on hooks the old plugin had

Status: asked for on 2026-10-18 ("go"), after comparing every action and filter the old plugin fired with the
rebuild; built the same day. Built on the extension API of item 27; the add-on guide is docs/extensions.md.

## Why

The old plugin let add-ons change prices (`orillacart_raw_price`, `orillacart_price_after_tax`), watch and stop
additions to the cart (`orillacart_before_add_to_cart`, `orillacart_after_add_to_cart`), add checkout fields
(`extend_billing_fields`, `extend_shipping_fields`), print into the product, cart and checkout pages
(`add_product_tabs_*`, `com_shop_before_cart`, `com_shop_after_checkout_fields`…), attach files to emails
(`orillacart_email_attachments`, `orillacart_email_headers`), add buttons to the order screen
(`orillacart_order_actions`) and lay out addresses (`billing_format`, `shipping_format`). The rebuild has
none of these, so a theme can only replace whole templates and an add-on cannot do any of it.

## Shape

What changes how the shop works goes through the registry as contracts in `OrillaCart\Extension` (plain PHP,
versioned; `Version::CURRENT` becomes 2). What only adds output, or adjusts a value WordPress-style, is a
WordPress action or filter named `orillacart_…`. Every one of them is documented in docs/extensions.md.

| | old | now |
|---|---|---|
| Prices | `orillacart_raw_price` and friends | `PriceAdjuster` |
| Cart | `orillacart_before_add_to_cart`, `orillacart_after_add_to_cart` | `CartRule`; actions `orillacart_cart_item_added`, `_changed`, `_removed` |
| Checkout fields | `extend_billing_fields`, `extend_shipping_fields` | `CheckoutField` (with an optional `FieldCheck`) |
| Page slots | `add_product_tabs_*`, `com_shop_before_cart`… | actions `orillacart_product_*`, `orillacart_cart_*`, `orillacart_checkout_*`, `orillacart_receipt_*`, `orillacart_account_*`, `orillacart_email_*` |
| Emails | `orillacart_email_attachments`, `orillacart_email_headers` | the same filters, with the email's kind and order number |
| Templates | `edit_template_paths_*` | filter `orillacart_template_paths` (after the theme's folder, before the plugin's) |
| Order screen | `orillacart_order_actions` | `OrderAction`; `$registry->orders()` to read an order |
| Addresses | `billing_format`, `shipping_format` | layouts by country, filter `orillacart_address_format` |

### Prices

`PriceAdjuster::adjust(PriceQuery): ?string` gets a product's or variation's own price after the shop's price
rules, with the quantity, the customer (null for a guest) and the currency, and returns another price or null
to keep it. Adjusters run in the order they were registered, each on the last one's price; a price below zero is
zero and an answer that is not a number is logged and ignored. Option prices and bundle pricing then apply as
before, so everything the shop shows or charges (product page, listings, cart, checkout, order editing) goes
through them. The search index keeps the guest price, as it does for price rules: an adjuster whose answer
changes with time has to reindex the products itself.

### Cart

`CartRule::check(CheckedCart): list<CartRefusal>` sees the whole cart (lines with product, variation, SKU, name,
quantity and unit price, and the customer) and refuses a line or the whole cart with a message for the customer.

- Adding an item or raising its quantity is refused (422) when a refusal names that line.
- The cart shows every refusal; its lines cannot be ordered until the customer changes them, and checkout
  refuses the order, as it does for stock.
- The cart line's problem gets a message the customer reads. Until now the cart page printed the bare codes
  (`insufficient_stock`), which this fixes.

Public actions, fired after the cart is saved: `orillacart_cart_item_added` (product id, variation id, quantity
added, customer id), `orillacart_cart_item_changed` (product id, variation id, new quantity, previous quantity,
customer id), `orillacart_cart_item_removed` (product id, variation id, quantity, customer id).

### Checkout fields

`CheckoutField` (id, label, kind `text` · `textarea` · `checkbox` · `choice`, section `billing` · `shipping` ·
`order`, required, choices, help, longest) is registered with `$registry->checkoutField()`. Checkout shows it in
its section, the shop checks what the kind and the field say, and a `FieldCheck` given with the field checks the
rest (a VAT number with the tax office, say) with the country and email as context. A shipping field is asked only
when the order ships.

The order keeps each answer with the label it had (`orders.fields`, JSON), like its lines keep their names: a
field removed later still shows on old orders. The receipt, the emails and the order screen show them; the
order screen edits them with the contact details. Orders the shop makes by hand do not ask them.

### Page slots

Templates call `slot('product_after_form', product.id)`; the shop runs `do_action('orillacart_product_after_form',
$productId)` and prints what the action echoed. Slots are where add-ons asked before: before and after the
product's buying form and after its details; before and after the cart, and after its totals; before and after
each part of checkout and before the button; after the receipt; after the account's pages; after an email's
order table. Arguments are ids and plain values, never the plugin's classes. A theme that overrides a template
keeps the slots it copies.

### Emails and templates

`orillacart_email_attachments` (paths, kind, order number) and `orillacart_email_headers` (header lines, kind,
order number) filter each order email when it is sent, in the mail job. `orillacart_template_paths` lets an add-on
bring its own templates (a richer receipt, say), searched after the theme's `orillacart/` folder.

### Order screen actions

`OrderAction` (`id()`, `label()`, `isAvailable(OrderSummary)`, `run(OrderSummary): string`) adds a button to the
order screen, such as "Send to the accounting system". Running it records a note with its answer; a
`ProviderUnavailable` shows the shop its message and records nothing. `OrderSummary` is the order as add-ons may
read it: number, status, dates, email, customer, addresses, lines, totals, currency, payment, shipping and fields.
`$registry->orders()->find($number)` gives the same summary to add-ons reacting to `orillacart_order_placed` and the
other order actions.

### Addresses

Addresses print in their country's layout: the default is name, street, postcode and city, region, country;
the United States, Canada, Australia, New Zealand, the United Kingdom, Ireland, Italy, India, Japan, China and
Hong Kong have their own. The filter `orillacart_address_format` (layout, country code) changes or adds one. The
receipt, the account and the emails use it; the admin edits addresses field by field, as before.

## Not carried over

`orillacart_add_to_cart_json` (WordPress's `rest_post_dispatch` filters any REST answer), the card-form hooks
(the card form is gone), the SEO and canonical filters (SEO plugins do this), and the old framework's own hooks
(`before_component_run`, `_build_sef`, …).
