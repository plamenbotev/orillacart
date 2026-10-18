# Writing an add-on

An add-on is a WordPress plugin that brings OrillaCart something it does not have, such as a payment provider
or a courier. It uses only the classes in the `OrillaCart\Extension` namespace: they are the extension API,
which changes only by adding (see `OrillaCart\Extension\Version`). Every other class of the plugin is internal
and changes without notice. The release build also renames the libraries the plugin ships (Doctrine, Symfony,
even the PSR interfaces), so an add-on never sees them.

## Registering

The shop fires `orillacart_extensions` once per request, the first time it needs its payment gateways or
shipping methods, and passes the registry:

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
  methods.
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
| `isReady($settings, $currency)` | whether checkout may offer it: credentials set, currency supported |
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

The shop settles each payment once; later returns and notifications change nothing. A completed outcome whose
amount or currency is not the order's is held for the shop to look at instead of completing the order.

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
