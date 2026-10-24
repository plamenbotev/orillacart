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
- An add-on that throws while registering is logged with where its callback is, and keeps what it registered
  before it threw; the add-ons after it still register.
- `$registry->shippingMethod(...)` registers a shipping method the same way; method ids are unique among
  methods. Price adjusters, cart rules, checkout fields and order actions follow (version 2).
- `$registry->version()` is the API's version (`OrillaCart\Extension\Version`): 2 since price adjusters, cart
  rules, checkout fields, order actions and `orders()` came; 4 since export columns; 5 since order fees and
  address checks; 6 since mailing lists; 7 since the answers to product forms on order lines; 8 since filters and
  searches of the orders and products lists, and `products()`; 9 since tax services, order adjusters and
  `orderPrices()`; 10 since e-invoice senders; 11 since gateways naming the account a payment is made in
  (`NamesAccounts`); 12 since mailing lists that delete contacts (`ForgetsSubscribers`).
- `$registry->log()` writes to the shop's log: debug.log, and its warnings and errors appear to the shop manager
  under Shop → Status → Problems and in Site Health, the same message once with how often it happened. Say what
  to do in them. Never log secrets or card data.
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

Throw `ProviderUnavailable` when the provider cannot be reached or refuses; the customer sees a general message,
the order is cancelled (its stock and coupon uses go back), and the customer keeps the cart to try again.

The session gives the payment id, the order number, the amount and currency, and for providers that show or
protect them the order's `items`, a `breakdown` (item total, tax, shipping, discount and, since version 5,
handling: the order's fees) and `shipTo`, the address the goods go to (null for a pickup point or nothing that ships). The items and breakdown come only when they
add up exactly to the amount; leave them out of the provider's request otherwise.

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
- A gateway whose notifications carry the provider's event id implements `NamesNotifiedEvents`:
  `eventId($notification)` reads it, and the shop handles each event once (kept a month).
- A refund outcome passes the shop's own id of a refund it asked for, as the provider echoes it
  (`PaymentOutcome::refunded(…, request: $id)`, the `RefundRequest->id` sent): the shop recognises its refund by
  it, never by the amount. A notification the gateway refuses is answered 400; one it cannot confirm because the
  provider is unavailable is answered 503 with `Retry-After`, so the provider sends it again.

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

A gateway whose settings can point at different accounts or modes of the provider (a sandbox and live, another
app) implements `NamesAccounts` (version 11): `account($settings)` names the one they point at. Each payment
keeps it; while the settings point elsewhere the shop does not ask about the payment, take its notifications,
or refund through it (the shop manager is told to switch back, or to refund at the provider), so switching to
the sandbox and back never cancels an order the other mode does not know.

How the shop reads outcomes, whatever the gateway: a customer who comes back through the cancel address ends the
payment but not the order, which may still be paid (the time the shop gives unpaid orders cancels it); and once
an order started another payment (paid another way through "Pay now"), what an earlier one says moves the order
only when it is money: an earlier payment completing on an order already paid flags it "Needs attention".

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
the shop's units (`weightUnit`, `dimensionUnit`), the subtotal and the currency; `weight('kg')` adds up the
items in the unit asked for, `dimensionalWeight('kg', 5000)` is what their size weighs to a courier, and
`length($item->length, 'cm')` converts a side (`Units` has the factors). Service codes must stay the same between quotes: checkout names an option "carrier:5:express", prices
it again when the order is placed, and keeps that name with the order.

- The shop keeps a quote for ten minutes per parcel and settings, so a customer filling in the form does not
  call the courier on every change.
- Throw `ProviderUnavailable` when the courier cannot be asked: checkout leaves the carrier out, logs it, and
  offers the others. The carrier stays out for a minute for that parcel before the courier is asked again.
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
  Return a `BookedShipment` with the courier's number and the tracking link, and since version 5 each parcel's
  own number when the courier gives them (`parcels`); throw `ProviderUnavailable` with the courier's reason
  when it refuses: the shop manager sees it.
- An order may go in several shipments: the shop chooses which items each carries, and the `Shipment` holds
  only those. The amount to collect comes with the first shipment of the order only.
- `label($number, $settings)` returns the `Label` (a PDF, usually) the shop prints.
- `cancelShipment($number, $settings)` cancels one the courier has not picked up; throw when it cannot.

A method that implements `TracksShipments` is asked every hour where the shipments on their way are:
`track($number, $settings)` returns a `Tracking` (on the way, delivered or returned, with the courier's
words). The order ships once everything is on its way, and completes once every shipment was delivered. The
receipt and the emails show every shipment with its tracking link, what it carries and its parcels' numbers.
Shipments the shop sent itself (with a courier it has no add-on for, entering each parcel's tracking number)
are never asked about: the shop says when they arrived.

## Prices

`OrillaCart\Extension\Pricing\PriceAdjuster` changes what a product costs: a wholesale price list, a surcharge,
prices from an ERP. `adjust(PriceQuery $query)` gets the product (and variation), the quantity, the customer (null
for a guest), the currency, the regular price and the price so far: after the shop's own price rules and the
adjusters registered before. Return another unit price as a decimal string, or null to keep it. Option prices,
bundle pricing and tax apply on top, so the product page, listings, cart, checkout and order editing all show
and charge it. An answer below zero is zero; one that is not a number, or an adjuster that throws, is logged and
skipped, and an order placed with the price unadjusted says so in a note for the shop. An adjuster the shop
cannot do without also implements `OrillaCart\Extension\FailsClosed`: when it fails, checkout (and the order the
shop makes by hand) refuses the order with `orillacart_prices_unavailable` and asks to try again in a moment.

The search index sorts and filters by the price a guest sees (`customerId` null). An adjuster whose answer
changes with time, not only with the product, quantity and customer, has to have the products reindexed then.

### Order prices

A price adjuster prices one product. `OrillaCart\Extension\Pricing\OrderAdjuster` (version 9, registered with
`$registry->orderAdjuster()`) prices the order as a whole: a loyalty discount on the basket, a price agreed with
a business customer, a handling surcharge. `adjust(AdjustmentQuery $query)` gets the order's items
(`AdjustedLine`: product, variation, name, quantity and what the line costs after its coupons, before tax), the
currency, the customer and, when the shop edits an order, its number. Return `Adjustment`s: a label the
customer and the invoice show, an amount below zero to lower the price, the line's index or null for the whole
order (spread over its items, rounded so the shares add up), and a reason for the shop's records. The order
keeps each one with your adjuster's id (kind `adjuster`), and its tax is worked out on what is left, so it is
filed right. Whenever the shop prices the order again (its items changed, or another change was made), your
adjuster is asked again and its answer replaces what it said before; the shop cannot take it off by hand.

An adjustment that takes off more than its line costs refuses checkout with `orillacart_invalid_price_change`;
an adjuster that throws, or answers with something that is not a number, is logged and skipped, unless it
implements `FailsClosed`.

`$registry->orderPrices()->adjust($number, $source, $label, $amount, $line, $reason)` changes the price of an
order later, such as when an ERP agrees a discount on an order waiting for a bank transfer. Only orders on
hold change: a paid order keeps its prices, and money goes back by refunds. The order is priced again, its
taxes with it, and its history says who changed it. Such a change (kind `add_on`) stays as it was made when the
order is priced again, and two alike stay two. The shop manager does the same on the order screen, and can take
off the shop's and add-ons' changes there.

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
  email, the countries and the customer) and returns the message to show next to the field, or null. A check that
  throws is logged and the answer taken, with a note on the order for the shop; one that implements `FailsClosed`
  refuses the answer instead, asking to try again in a moment.
- The order keeps each answer with the label it had: a choice by its label, a ticked box as "Yes". The receipt,
  the emails and the order screen show them, the order screen corrects them, and `OrderSummary::$fields` gives
  them to add-ons by field id. Orders the shop makes by hand do not ask them.
- The shop's own checkout forms (Shop → Forms) are asked after add-ons' fields and checked the same way. Their ids
  are `f<form id>_<key>`; an add-on's id must not start that way.

## Order fees

Version 5 lets an add-on charge a fee on orders: a surcharge for a bulky order, insurance, a delivery that
needs a crane. `OrillaCart\Extension\Checkout\OrderFee::fee(FeeQuery $query)` sees the order as checkout has it
(its items, the subtotal less discounts, shipping, the way to ship and to pay chosen, where it goes, the
customer and the currency) and returns a `Fee` or null:

```php
if ($registry->version() >= 5) {
    $registry->orderFee(new class implements OrillaCart\Extension\Checkout\OrderFee {
        public function id(): string { return 'bulky'; }
        public function fee(OrillaCart\Extension\Checkout\FeeQuery $query): ?OrillaCart\Extension\Checkout\Fee
        {
            $items = array_sum(array_map(static fn($item): int => $item->quantity, $query->items));

            return $items > 10 ? new OrillaCart\Extension\Checkout\Fee(__('Bulky order', 'my-add-on'), '4.50') : null;
        }
    });
}
```

- The amount is as the shop's prices are: before tax, or including it when the shop's prices include tax. A fee
  is taxed as an item of its tax group is (`taxGroupId`, null for the shop's default), unless `taxable` is false.
  Zero or less is no fee.
- The shop's own fee for a way to pay (Payments → the method → Fee) comes first, then the add-ons', in the order
  they were registered. Checkout, the order the shop takes by hand, and editing an order on hold price them all.
- The order keeps each fee with its label; the receipt, the emails, the invoice and the order screen show them,
  `OrderSummary::$fees` gives them to add-ons (amount by label), and a payment gateway finds them as the
  `handling` of the `PaymentBreakdown`.
- A fee that throws, or answers what is not a label and an amount, is logged and left out, and an order placed
  without it says so in a note for the shop. One that implements `OrillaCart\Extension\FailsClosed` refuses the
  order instead, asking the customer to try again in a moment.

## Order actions and reading orders

`OrillaCart\Extension\Order\OrderAction` puts a button on the order screen ("Send to the accounting system").
`isAvailable(OrderSummary)` says whether the order offers it; `run(OrderSummary)` does it and returns what it did,
which the order's history records with the button's label. Throw `ProviderUnavailable` when it could not: the
shop manager sees the message and nothing is recorded.

`$registry->orders()->find($number)` gives an `OrderSummary`: number, status, dates, email, customer,
addresses, lines, totals, refunds, currency, payment, shipping, the customer's note and the field answers. Each
`OrderLine` has, from API 7, `personalisation`: the answers to the shop's product forms (an engraving, a file by
its name), each with its label and what it added to an item. Use it
from the `orillacart_order_…` actions listed below, which pass only the number; keep the registry the
`orillacart_extensions` action gave you.

## Export columns

Version 4 lets an add-on add columns to the shop's exports. The shop manager sees them beside the shop's own in
the export dialog, chooses which to export and in what order, and the shop keeps that choice.

- `OrillaCart\Extension\Order\OrderColumn` is a column of the orders export: `id()`, `label()` (the dialog's and
  the file's header) and `value(OrderSummary)`, the order's cell. Register it with `$registry->orderColumn(...)`.
- `OrillaCart\Extension\Catalog\ProductColumn` is a column of the products file, with `value(ProductItem)` for
  a product or one of its variations (`ProductItem` has their ids, name, SKU and price). Register it with
  `$registry->productColumn(...)`. Implement `ImportsProductColumn` too and the import reads the column back:
  `import(ProductItem, $value)` runs after the shop saved the row's product; throw `InvalidArgumentException` to
  report the row's value as refused.

Column ids are unique among their kind, the shop's own ids included (`number`, `email`, `total`, `sku`,
`price`…): a column with a taken id is logged and left out. A column that throws gives an empty cell, and is
logged.

```php
if ($registry->version() >= 4) {
    $registry->orderColumn(new class implements OrillaCart\Extension\Order\OrderColumn {
        public function id(): string { return 'warehouse'; }
        public function label(): string { return __('Warehouse', 'my-add-on'); }
        public function value(OrillaCart\Extension\Order\OrderSummary $order): string
        {
            return (string) get_option('my_warehouse_of_' . $order->number, '');
        }
    });
}
```

## Filters and searches of the lists

Version 8 lets an add-on help the shop find orders and products by what the add-on knows about them.

- `OrillaCart\Extension\Order\OrderFilter` is a filter of the Orders screen, under "Details" ("Exported to the
  warehouse"): only `id()` and `label()`. The add-on marks the orders it applies to when that happens, with
  `$registry->orders()->mark($number, 'filter_id')`, and takes the mark off with `unmark()` (`isMarked()` reads
  it). The shop keeps the marks and filters by them in the database, so a filter on a hundred thousand orders
  costs what one on ten does, and the add-on is not asked while the list loads. A mark goes with its order.
- `OrillaCart\Extension\Catalog\ProductFilter` is the same for the products list, which offers the add-ons'
  filters in one list: mark with `$registry->products()->mark($productId, 'filter_id')`.
- `OrillaCart\Extension\Order\FindsOrders` and `OrillaCart\Extension\Catalog\FindsProducts` answer the search
  boxes by the add-on's own text (a warehouse's reference, a barcode): `findOrders(string)` gives order numbers,
  `findProducts(string)` product ids, of which the shop takes 500 and lists them beside what it finds itself.
  Orders are searched from three characters.

Register them with `$registry->orderFilter()`, `orderSearch()`, `productFilter()` and `productSearch()`. Ids are
lowercase letters, digits and underscores, up to 40; an order filter cannot take the id of one of the shop's own
(`coupon`, `return`, `gift_card`, `backorder`, `personalised`, `note`, `downloads`): it is logged and left out.
A search that throws or answers with something other than ids is logged and finds nothing; the list still shows.
Marking with an id that cannot be one throws `InvalidArgumentException`; marking an order or product the shop
does not have returns false.

```php
if ($registry->version() >= 8) {
    $registry->orderFilter(new class implements OrillaCart\Extension\Order\OrderFilter {
        public function id(): string { return 'warehouse_sent'; }
        public function label(): string { return __('Sent to the warehouse', 'my-add-on'); }
    });
    add_action('my_warehouse_accepted', static function (int $number) use ($registry): void {
        $registry->orders()->mark($number, 'warehouse_sent');
    });
}
```

## Tax services

Version 9 lets an add-on work out the shop's taxes with a tax service (Avalara, TaxJar, Stripe Tax, Vertex),
as shops selling across US states need: rates below the state (counties, cities, districts) follow street
addresses, not postcodes. Register an `OrillaCart\Extension\Tax\TaxProvider` with `$registry->taxProvider()`;
the shop manager chooses it in Shop → Tax, and what checkout does when it cannot be reached: tax at the shop's
own rates and note it on the order, or ask the customer to try again (a provider that implements
`FailsClosed` always refuses).

- `quote(TaxRequest)`: checkout, manual and edited orders ask for the tax of every line, the items, the shipping
  and each fee (`TaxRequestLine`: an id such as `item:0`, `shipping` or `fee:1`, its kind, the shop's tax group
  id as `taxCode`, the quantity and what it costs before tax after its discounts). `serviceCode` is what your
  service calls the goods (`PC040100`, `txcd_99999999`): the shop manager sets it on each tax group in Shop → Tax,
  and on a product whose goods differ from its group's; null when they set none. The request has the ship-from
  and ship-to addresses (street and city once the customer typed them), the customer, their exemption
  certificate when the shop has one on file, and the order being edited. Answer a `TaxResult` of `TaxLine`s by
  line id: each rate's name, percent, jurisdiction (country, region, level, the code returns are filed under,
  its name), what it was charged on, the amount, and `exempt` for a rate the exemption covers (amount 0). The
  shop keeps an answer ten minutes for the same request (lines, addresses, customer, day), so the cart,
  checkout and placing the order ask once; answers it cannot use (unknown lines, numbers that are not,
  tax below zero) count as a failure.
- `commit(TaxedOrder)`: the order was paid; record the sale with the taxes it charged and return your reference,
  which the order keeps. `refund(TaxedOrder, TaxRefund)` reports the tax inside money given back, and
  `void(TaxedOrder)` a recorded order cancelled. They run as background jobs (lane `tax`) and are tried again
  when they throw.

Only orders your service taxed are committed to it; orders taxed at the shop's own rates, and prices that
include tax, never reach it.

## E-invoices

When the shop makes e-invoices (Settings → Invoices: UBL as Peppol BIS Billing 3.0, or CII as XRechnung), each
invoice and credit note is also an EN 16931 XML file: downloaded from its page (`&format=xml`), and on the
customer's emails next to the PDF. Version 10 lets an add-on send them on, through a Peppol access point or a
country's own platform: register an `OrillaCart\Extension\Invoice\EInvoiceSender` with
`$registry->eInvoiceSender()`.

- `send(EInvoiceFile)`: a document was issued (an order paid, money given back). The file has its number, kind
  (`invoice` or `credit_note`), the order, the format, the file name and the XML, and the buyer's email, country,
  tax number and own reference to find the recipient. `buyerPeppolId` (such as `9930:DE123456789`) is set when
  the file is for Peppol: UBL, and both the shop (its Peppol ID in Settings → Invoices, or its VAT number's) and
  the buyer (their VAT number's) have one; a consumer's invoice is plain EN 16931 and has none. It runs as a background job (lane `invoices`), one for each
  sender, and is tried again when it throws.

## Mailing lists

Version 6 lets an add-on send the newsletter's sign-ups to a newsletter service (Mailchimp, Brevo…). The shop
keeps every sign-up itself, with the words the customer agreed to, when and where (checkout or their account);
the shop manager turns each add-on's list on or off (Shop → Customers → Newsletter), and can download the
subscribers.

```php
if ($registry->version() >= 6) {
    $registry->mailingList(new class implements OrillaCart\Extension\Marketing\MailingList {
        public function id(): string { return 'acme_mail'; }
        public function name(): string { return __('Acme Mail: Customers', 'my-add-on'); }
        public function subscribe(OrillaCart\Extension\Marketing\Subscriber $subscriber): void
        {
            $response = wp_remote_post('https://api.acme-mail.example/members', ['body' => [
                'email' => $subscriber->email,
                'name' => trim("{$subscriber->firstName} {$subscriber->lastName}"),
                'consent' => $subscriber->consent,
                'consented_at' => $subscriber->subscribedAt->format(DATE_ATOM),
            ]]);
            if (is_wp_error($response) || wp_remote_retrieve_response_code($response) >= 500) {
                throw new OrillaCart\Extension\ProviderUnavailable('Acme Mail', 'not reachable');
            }
        }
        public function unsubscribe(string $email): void { /* … */ }
    });
}
```

- The shop tells a list in the background (a job in the mail lane), never while the customer waits: of each
  sign-up, of each unsubscribe in the account, and of the people the privacy tools erase. Turned on, a list gets
  everyone subscribed so far.
- Throw `ProviderUnavailable` when the service cannot be reached: the shop asks again later, up to six times.
  Anything else is logged, and the job is left among the failed ones (Shop → Background jobs) to retry.
- `subscribe()` may come again for someone already on the list (a retry, a list turned on again): treat it as
  updating them.
- When the service says someone unsubscribed there (its own link, a complaint), tell the shop:
  `do_action('orillacart_newsletter_unsubscribed', $email, $listId)` (version 12). They leave the shop's
  newsletter, and the other lists hear it, so turning on another list never signs them up again.
- A list whose service can delete a contact implements `ForgetsSubscribers` (version 12): `forget($email)` is
  called for a person the privacy tools erase, on every list the shop knows, the ones turned off included; the
  others get `unsubscribe()`.
- A customer who changes their email in WordPress takes their sign-up along: the list hears the old address leave
  and the new one join. Every shop email to someone subscribed carries a signed link to unsubscribe, which asks
  them to confirm.

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

- `orillacart_email_attachments` filters the files attached to an email (paths that exist), with the email's
  kind and its order number. The kinds about an order are `order_received`, `payment_reminder`,
  `order_processing`, `order_backordered`, `tracking_added`, `order_shipped`, `order_completed`,
  `order_cancelled`, `order_failed`, `order_refunded`, `customer_note` and `receipt_link` to the customer, and
  `new_order`, `order_attention`, `payment_failed` and `refund_made` to the shop. The others have no order:
  `account_confirm`, `account_exists` and `account_welcome` to the customer, `back_in_stock` to a visitor who
  asked, `low_stock` to the shop. Order emails
  go out from a background job, so the filter runs there.
- `orillacart_email_headers` filters the header lines, with the same arguments.
- `orillacart_template_paths` adds folders of templates: the shop looks in the theme's `orillacart/` folder, then
  in these, then in its own `templates/`. A file with the same path (`storefront/receipt.html.twig`) replaces the
  shop's.
- Email templates (`emails/`) can also be written by the shop on the Emails screen; its own comes before every
  file of the name. Emails render apart from the storefront, always in Twig's sandbox: a template may use Twig's
  tags, filters and tests (not `constant`, `source` or calling methods), the shop's `__`, `_n`, `money`,
  `shop_date`, `address_lines` and `slot`, and the values the email is given. A theme's or add-on's email
  template has to keep to that too.

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

The shop refuses an order whose postcode does not look like its country's (about sixty countries; any other
country takes any postcode), and keeps postcodes in capitals with single spaces.

### Address checks

Version 5 lets an add-on check the addresses customers enter against a register (Loqate, Google, the post
office's). `OrillaCart\Extension\Checkout\AddressCheck::check(CheckedAddress $address)` gets the billing or
shipping address (`kind`, street, city, postcode, country, state) and returns null when it is fine or the check
cannot tell, `AddressSuggestion::corrected($street, $city, $postcode, $state, $message)` (the parts left null
stay as entered) or `AddressSuggestion::notFound($message)`. Register it with `$registry->addressCheck(...)`.

- When the customer places the order, checkout asks first (`POST /checkout/addresses`, thirty an hour per
  visitor) and shows what the check said: the customer takes the corrected address or keeps theirs. The first
  check with something to say is heard.
- A check never keeps the customer from ordering. An order placed with an address a check would have corrected,
  or could not find, says so in a note for the shop.
- A check that throws is logged and left out. Keep it quick: it runs while the customer waits.

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
| `orillacart_order_paid` | order number |
| `orillacart_product_viewed` | product id, customer id (null for a guest); not for pages a page cache serves: count those in the browser (below) |

| filter | value | arguments |
|---|---|---|
| `orillacart_verify_request` | `true`; a `WP_Error` (or `false`) refuses one of the shop's public forms with its message, as a captcha does | the form (`accounts`, `account_links`, `coupons`, `checkout`, `session`), what it sent |
| `orillacart_rate_limits` | `{form: [how many, in seconds]}` each address and each cart may send; leave a form out to count none | |
| `orillacart_email_attachments` | file paths | email kind, order number |
| `orillacart_email_headers` | header lines | email kind, order number |
| `orillacart_template_paths` | template folders an add-on brings | |
| `orillacart_address_format` | a country's address layout | country code |
| `orillacart_product_structured_data` | the schema.org Product a product's page gives search engines; empty leaves it out | product id |
| `orillacart_seo_plugin_active` | whether an SEO plugin writes Open Graph tags, so the shop's are left out (Settings → Storefront: unless an SEO plugin adds them) | |

The page slots above are actions too.

### Events in the browser

The storefront script fires events on `document` as shoppers go, for analytics and marketing add-ons, each with
the event's data as Google Analytics 4 names it (`currency`, `value`, `items` of `item_id`, `item_name`,
`item_variant`, `price` and `quantity`):

| event | when |
|---|---|
| `orillacart:view_item` | a product's page shows |
| `orillacart:add_to_cart` | a product goes in the cart, from its page or a list |
| `orillacart:begin_checkout` | the checkout shows the cart |
| `orillacart:purchase` | the receipt of an order placed shows, once per browser (with `transaction_id`, `tax`, `shipping`, `coupon`) |

```js
document.addEventListener( 'orillacart:purchase', ( event ) => {
	myPixel.track( 'Purchase', { value: event.detail.value, currency: event.detail.currency } );
} );
```

With Settings → Storefront → "Send the shop's events to Google Tag Manager", the same events are also pushed to
`window.dataLayer` as GA4 ecommerce events (`{ event: 'add_to_cart', ecommerce: { … } }`).

WordPress's own `registration_errors` is left to the wp-login.php form: captcha plugins on it expect that form's
fields and would refuse every shop sign-up. The shop's forms carry a field people never see, `orillacart_website`:
what comes with it filled in is refused, before `orillacart_verify_request` is asked. Signing up never says whether
the email has an account: the link to make it, or word that it has one, goes to the email.

## Webhooks

Shop → Integrations sends webhooks: each event a webhook listens to is POSTed to its address as JSON, by the
background jobs, and tried again (up to six times over about a day) until the address answers 2xx:

```json
{ "id": "6f1c…", "topic": "order.placed", "created_at": "2026-10-21T08:00:00+00:00", "data": { "order": { … } } }
```

The shop's topics are `order.placed`, `order.paid`, `order.status_changed` (with `status` and `previous_status`),
`order.refunded` (with `amount` and `refunded_in_full`), `order.deleted`, `product.changed` and `stock.changed`
(with `item`, `before` and `after`); orders and products come as the shop's API shows them, as they are when
delivered. `webhook.ping` is the screen's test.

Each request carries `X-OrillaCart-Event` (the event's id, the same on every try), `X-OrillaCart-Topic`,
`X-OrillaCart-Attempt` and `X-OrillaCart-Signature: t=<unix time>,v1=<hex>`: the HMAC-SHA256, with the webhook's
secret, of `<time>.<body>`. Check it, and refuse an old time, before trusting the body:

```php
[$time, $mac] = sscanf( $_SERVER['HTTP_X_ORILLACART_SIGNATURE'], 't=%d,v1=%s' );
$ok = abs( time() - $time ) < 300 && hash_equals( hash_hmac( 'sha256', $time . '.' . $body, $secret ), $mac );
```

An add-on adds topics of its own and sends their events:

```php
add_filter( 'orillacart_webhook_topics', fn( array $topics ) => $topics + [ 'loyalty.points_earned' => 'Points earned' ] );

do_action( 'orillacart_send_webhook', 'loyalty.points_earned', [ 'customer' => 12, 'points' => 50 ] );
```

## Translations

The plugin's text domain is `orillacart`. `npm run i18n:pot` writes `languages/orillacart.pot` from the PHP
code, the built scripts and the Twig templates (a `{# translators: … #}` comment on the line before a string
reaches the translators). Translations shipped with the plugin go in `languages/` as `orillacart-<locale>.mo`
and, for the scripts, the JSON files `wp i18n make-json` writes next to the `.po`.
