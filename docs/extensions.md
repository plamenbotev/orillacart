# Writing an add-on

An add-on is a WordPress plugin that brings OrillaCart something it does not have, such as a payment provider
or a courier. It uses only the classes in the `OrillaCart\Extension` namespace: they are the extension API,
which changes only by adding (see `OrillaCart\Extension\Version`). Every other class of the plugin is internal
and changes without notice. The release build also renames the libraries the plugin ships (Doctrine, Symfony,
even the PSR interfaces), so an add-on never sees them.

## Registering

The shop fires `orillacart_extensions` once per request, the first time it needs something add-ons register
(a price, a cart, checkout, a payment gateway, a shipping method), and passes the registry:

```php
add_action('orillacart_extensions', static function (OrillaCart\Extension\Registry $registry): void {
    $registry->paymentGateway(new Acme\Pay\AcmeGateway($registry->log()));
});
```

- Ids are lowercase letters, digits and underscores, up to 40 characters, and unique across the shop. The
  shop's own gateways (`cash_on_delivery`, `bank_transfer`, `cheque`, `paypal`) come first; a second
  registration with a taken id is logged and left out.
- An add-on that throws while registering is logged; the shop goes on without it.
- `$registry->shippingMethod(...)` registers a shipping method the same way; method ids are unique among
  methods. Price adjusters, cart rules, checkout fields and order actions follow (version 2).
- `$registry->version()` is the API's version (`OrillaCart\Extension\Version`): 2 since price adjusters, cart
  rules, checkout fields, order actions and `orders()` came.
- `$registry->log()` writes to the shop's log. Never log secrets or card data.
- Call the provider with the WordPress HTTP API (`wp_remote_post()` and friends).

## Settings

A gateway lists its settings as `OrillaCart\Extension\Setting`s: text, secret, yes/no, number or choice. The
shop's admin screen shows them, validates their kind, and keeps the values; the gateway receives them as
`OrillaCart\Extension\Settings` (`text()`, `flag()`). Secrets never go back to the browser: the screen shows
only that one is saved, and an empty field keeps it. A required setting must be filled before the method can be
turned on.

## Payment gateways

Implement `OrillaCart\Extension\Payment\PaymentGateway`:

| method | |
|---|---|
| `id()`, `defaultName()` | the shop renames the method as it likes |
| `settings()` | see above |
| `notReady($settings, $currency, $decimals)` | why checkout may not offer it (credentials missing, currency or its decimals not taken), or null |
| `start($session, $settings)` | `PaymentStart::offline()` (the order waits on hold for the money), or `PaymentStart::redirect($url, $reference)` to send the customer to the provider; `$reference` is the provider's id of the payment |

Throw `ProviderUnavailable` when the provider cannot be reached or refuses; the customer sees a general message
and the order is cancelled so they can try again.

A gateway that sends customers away also implements `HandlesReturns`. The session carries the shop's
`returnUrl` and `cancelUrl`; the provider sends the customer back there.

- `reference($return)` reads which payment the return is about from its query string.
- `returned($reference, $return, $settings)` says how it stands: `PaymentOutcome::completed()`, `held()`,
  `failed()`, `cancelled()` or `pending()` when it cannot tell yet. The shop asks only while the payment is open,
  so a customer coming back twice does not reach the provider twice. The customer then sees the receipt.

A gateway whose provider calls back implements `HandlesNotifications`. The admin screen shows the address
(`/wp-json/orillacart/v1/payments/{id}/webhook`, also in the session as `notificationUrl`).

- `notified($notification, $settings)` must first check that the provider sent it (a signature with a shared
  secret, or asking the provider) and throw `InvalidNotification` otherwise. Then it returns the outcome for the
  payment it names, or null for anything else.
- A gateway whose notifications make it act at the provider (capturing a payment the customer approved, say)
  also implements `NamesNotifiedPayments` (version 3): `paymentReference($notification)` reads which payment the
  notification is about, without checking it. The shop calls `notified()` only for payments it started, so a
  provider account shared with another site never captures that site's money here. Return null for
  notifications about no payment the shop started, such as refunds made at the provider's site.

The shop settles each payment once; later returns and notifications change nothing. A completed outcome whose
amount or currency is not the order's is held for the shop to look at instead of completing the order.

A gateway whose provider can give money back implements `RefundsPayments`; the order screen then offers
"Refund through …" on paid orders. For other gateways the shop records refunds it made itself.

- `refund($request, $settings)` refunds `$request->amount` of the payment, found by its `reference` or
  `transactionId` (as the outcome gave them). Return `RefundResult::done($providerId)`, or `pending()` when the
  provider finishes it later. Throw `RefundRefused` with the reason when the provider says no, and
  `ProviderUnavailable` when it does not answer: the refund then waits, and the shop tries it again with the same
  `$request->id`. Pass that id to the provider as its idempotency key, so a retry never refunds twice.
- A refund made at the provider's own site reaches the shop as a notification: return
  `PaymentOutcome::refunded($reference, $refundId, $amount, $currency, $transactionId)`. The shop records it once
  per `$refundId`, and recognises its own refunds by that id. A refund the provider accepted with `pending()` is
  recorded on the order (stock back, the customer's email) only once it is done: return the same `refunded()`
  outcome when the provider tells of it.
- When the money of a paid order is in question (reversed, disputed, a chargeback), return
  `PaymentOutcome::disputed($reference, $reason, $transactionId)` (version 3): the order keeps its status, is
  flagged "Needs attention" with the reason, and the shop is emailed.

Version 3 lets the shop ask the provider itself, every hour, so payments settle even when a notification is lost:

- `ChecksPayments::check($reference, $transactionId, $settings)` says how a payment stands that the shop heard
  nothing final of for a quarter of an hour (held for review, or a customer who never came back). Take the
  money of an approved payment here as on its notification.
- `ChecksRefunds::checkRefund($reference, $settings)` says how a refund accepted with `pending()` stands:
  `RefundResult::done()`, `pending()` while it waits, or throw `RefundRefused` when the provider gave up on it
  (the refund fails and the order is flagged for the shop).

`tests/Fixtures/Extension/TestPayGateway.php` is a complete example, and the shop's PayPal gateway
(`src/Payment/Application/PayPal/PayPalGateway.php`) is built the same way.

## Shipping methods

A shipping method prices a courier's own services, such as DPD's. The shop manager makes a carrier, chooses
the method under "Prices from" and fills in its settings; the carrier keeps its name and place at checkout,
and the method's services are its options. Implement `OrillaCart\Extension\Shipping\ShippingMethod`:

| method | |
|---|---|
| `id()`, `name()` | |
| `settings()` | as for gateways; the carrier keeps the values |
| `isReady($settings)` | whether it can quote; checkout skips the carrier otherwise |
| `quote($shipment, $settings)` | the services that can ship the parcel: `new Rate($code, $name, $price, $deliveryDays)`, prices before tax in the shipment's currency; none when the courier does not serve the address |

The `Shipment` carries the destination (country, state, postcode), the items with their weight and sizes in
the shop's units (`weightUnit`, `dimensionUnit`), the subtotal and the currency; `weight()` adds up the
items. Service codes must stay the same between quotes: checkout names an option "carrier:5:express", prices
it again when the order is placed, and keeps that name with the order.

- The shop keeps a quote for ten minutes per parcel and settings, so a customer filling in the form does not
  call the courier on every change.
- Throw `ProviderUnavailable` when the courier cannot be asked: checkout leaves the carrier out, logs it, and
  offers the others.
- Whether the method's prices are taxed, and by which tax group, is the carrier's setting.

`tests/Fixtures/Extension/TestShipMethod.php` is a complete example.

### Pickup points

A service that delivers to an office or locker returns its rate with `toPickupPoint: true`, and the method
implements `OffersPickupPoints`: `pickupPoints($query, $settings)` lists the points for the service near the
address (`PickupQuery`: service, country, postcode, city), as `PickupPoint`s with a stable id, a name, an
address and opening hours. Checkout lists them under the service and will not place the order without one; the
order keeps the point's id, name and address, and its shipment goes there. The shop keeps the list for an hour.

### Shipments, labels and tracking

A method that books shipments implements `CreatesShipments`. The order screen then offers "Book with …" for
orders shipped by the carrier's services:

- `createShipment($request, $settings)` books it. The `ShipmentRequest` has the reference ("Order #1042"),
  the service, the recipient and address, the pickup point, the contents as a `Shipment`, the number of
  parcels, the declared value and, for an unpaid cash-on-delivery order, the amount the courier collects.
  Return a `BookedShipment` with the courier's number and the tracking link; throw `ProviderUnavailable` with
  the courier's reason when it refuses: the shop manager sees it.
- `label($number, $settings)` returns the `Label` (a PDF, usually) the shop prints.
- `cancelShipment($number, $settings)` cancels one the courier has not picked up; throw when it cannot.

A method that implements `TracksShipments` is asked every hour where the shipments on their way are:
`track($number, $settings)` returns a `Tracking` (on the way, delivered or returned, with the courier's
words). A delivered shipment completes its order when the order is shipped. The receipt and the emails show
every shipment with its tracking link.

## Prices

`OrillaCart\Extension\Pricing\PriceAdjuster` changes what a product costs: a wholesale price list, a surcharge,
prices from an ERP. `adjust(PriceQuery $query)` gets the product (and variation), the quantity, the customer (null
for a guest), the currency, the regular price and the price so far: after the shop's own price rules and the
adjusters registered before. Return another unit price as a decimal string, or null to keep it. Option prices,
bundle pricing and tax apply on top, so the product page, listings, cart, checkout and order editing all show
and charge it. An answer below zero is zero; one that is not a number, or an adjuster that throws, is logged and
skipped.

The search index sorts and filters by the price a guest sees (`customerId` null). An adjuster whose answer
changes with time, not only with the product, quantity and customer, has to have the products reindexed then.

## Cart rules

`OrillaCart\Extension\Cart\CartRule::check(CheckedCart $cart)` sees the cart (the customer, and each line's
product, variation, SKU, name, quantity and unit price) and returns refusals:
`CartRefusal::line($line->id, 'At most 3 to an order.')` or `CartRefusal::cart('…')`, worded for the customer.

- Adding an item, or raising its quantity, is refused with the message when a refusal names that line. A line
  being added has the id 0.
- The cart shows every refusal, and cannot be checked out until the customer changes it; checkout refuses the
  order too.
- A rule that throws is logged and left out.

## Checkout fields

`OrillaCart\Extension\Checkout\CheckoutField` asks the customer something at checkout:

```php
$registry->checkoutField(new CheckoutField('vat_number', 'VAT number', check: new Acme\Vies\VatCheck()));
$registry->checkoutField(new CheckoutField('terms', 'I accept the terms', CheckoutField::CHECKBOX, CheckoutField::ORDER, required: true));
```

- Kinds: `TEXT`, `TEXTAREA`, `CHECKBOX` (required means ticked), `CHOICE` (with `choices`, value => label).
- Sections: `BILLING` (after the billing address), `SHIPPING` (after the shipping address, only for orders that
  ship), `ORDER` (after the order's note). `help` shows under the field; `longest` caps its length.
- The shop checks what the field says; a `FieldCheck` checks the rest (`check($answer, FieldContext)` with the
  email, the countries and the customer) and returns the message to show next to the field, or null.
- The order keeps each answer with the label it had: a choice by its label, a ticked box as "Yes". The receipt,
  the emails and the order screen show them, the order screen corrects them, and `OrderSummary::$fields` gives
  them to add-ons by field id. Orders the shop makes by hand do not ask them.

## Order actions and reading orders

`OrillaCart\Extension\Order\OrderAction` puts a button on the order screen ("Send to the accounting system").
`isAvailable(OrderSummary)` says whether the order offers it; `run(OrderSummary)` does it and returns what it did,
which the order's history records with the button's label. Throw `ProviderUnavailable` when it could not: the
shop manager sees the message and nothing is recorded.

`$registry->orders()->find($number)` gives an `OrderSummary`: number, status, dates, email, customer,
addresses, lines, totals, refunds, currency, payment, shipping, the customer's note and the field answers. Use it
from the `orillacart_order_…` actions listed below, which pass only the number; keep the registry the
`orillacart_extensions` action gave you.

## Page slots

The templates print what add-ons echo in these actions:

| action | where | arguments |
|---|---|---|
| `orillacart_product_before_form`, `orillacart_product_after_form`, `orillacart_product_after_details` | the product's buying panel | product id |
| `orillacart_cart_before`, `orillacart_cart_after_totals`, `orillacart_cart_after` | the cart | |
| `orillacart_checkout_before_details`, `orillacart_checkout_after_billing`, `orillacart_checkout_after_shipping`, `orillacart_checkout_after_details` | the checkout form | |
| `orillacart_checkout_before_place_order` | above the "Place order" button (reloaded with the summary) | |
| `orillacart_receipt_after` | the receipt, after checkout and in the account | order number |
| `orillacart_account_after` | the account page | the part shown: `orders`, `order`, `downloads` or `addresses` |
| `orillacart_email_after_order` | each order email, after the addresses | order number, whether it is the shop's email |

Escape what you print. A theme that overrides a template keeps the slots it copies.

## Emails and templates

- `orillacart_email_attachments` filters the files attached to an order email (paths that exist), with the email's
  kind (`order_received`, `order_processing`, `order_shipped`, `order_completed`, `order_cancelled`,
  `order_failed`, `order_refunded`, `customer_note`, `new_order`) and the order number. Emails go out from a
  background job, so the filter runs there.
- `orillacart_email_headers` filters the header lines, with the same arguments.
- `orillacart_template_paths` adds folders of templates: the shop looks in the theme's `orillacart/` folder, then
  in these, then in its own `templates/`. A file with the same path (`storefront/receipt.html.twig`) replaces the
  shop's.

## Addresses

Receipts, the account and emails print addresses in their country's layout: name, street, postcode and city,
region, country by default, and their own for the United States, Canada, Australia, New Zealand, the United
Kingdom, Ireland, Italy, India, Japan, China and Hong Kong. The filter `orillacart_address_format` (layout,
country code) changes a country's layout. A layout is lines of `{name}`, `{first_name}`, `{last_name}`,
`{street}`, `{city}`, `{postcode}`, `{state}`, `{state_code}` and `{country}`; empty parts drop out with their
separators, and empty lines go:

```php
add_filter('orillacart_address_format', static fn(string $format, string $country): string =>
    $country === 'BG' ? "{name}\n{street}\n{city} {postcode}\n{country}" : $format, 10, 2);
```

## Actions and filters

Besides the registry, the shop announces what happens as WordPress actions, after the change is saved, and
lets plugins adjust a few things through filters. Arguments are ids and plain values.

| action | arguments |
|---|---|
| `orillacart_order_placed` | order number |
| `orillacart_order_status_changed` | order number, new status, previous status |
| `orillacart_order_changed` | order number (its items, its customer's details or whose order it is) |
| `orillacart_order_refunded` | order number, amount, whether it is now refunded in full |
| `orillacart_order_note_added` | order number, the note, whether it is for the customer |
| `orillacart_order_deleted` | order number |
| `orillacart_product_changed` | product id |
| `orillacart_catalog_items_removed` | product ids, variation ids, option ids |
| `orillacart_stock_changed` | item (`product:12`, `variation:30`, `option:7`), how many could be sold before and after (null: unlimited) |
| `orillacart_price_rules_changed` | the rule's scopes before and after: each its `groups`, `products`, `categories` and `brands` ids (none: everyone, or the whole catalog) |
| `orillacart_tax_rates_changed` | tax group id |
| `orillacart_settings_changed` | the settings now and before |
| `orillacart_cart_item_added` | product id, variation id (null for none), how many were added, customer id (null for a guest) |
| `orillacart_cart_item_changed` | product id, variation id, the new quantity, the previous one, customer id |
| `orillacart_cart_item_removed` | product id, variation id, the quantity it had, customer id |

| filter | value | arguments |
|---|---|---|
| `orillacart_verify_request` | `true`; a `WP_Error` (or `false`) refuses one of the shop's public forms with its message, as a captcha does | the form (`accounts`, `account_links`, `coupons`, `checkout`, `session`), what it sent |
| `orillacart_rate_limits` | `{form: [how many, in seconds]}` each address and each cart may send; leave a form out to count none | |
| `orillacart_email_attachments` | file paths | email kind, order number |
| `orillacart_email_headers` | header lines | email kind, order number |
| `orillacart_template_paths` | template folders an add-on brings | |
| `orillacart_address_format` | a country's address layout | country code |

The page slots above are actions too.

WordPress's own `registration_errors` is left to the wp-login.php form: captcha plugins on it expect that form's
fields and would refuse every shop sign-up. The shop's forms carry a field people never see, `orillacart_website`:
what comes with it filled in is refused, before `orillacart_verify_request` is asked. Signing up never says whether
the email has an account: the link to make it, or word that it has one, goes to the email.

## Translations

The plugin's text domain is `orillacart`. `npm run i18n:pot` writes `languages/orillacart.pot` from the PHP
code, the built scripts and the Twig templates (a `{# translators: … #}` comment on the line before a string
reaches the translators). Translations shipped with the plugin go in `languages/` as `orillacart-<locale>.mo`
and, for the scripts, the JSON files `wp i18n make-json` writes next to the `.po`.
