# 27 · Public extension API for add-ons

Status: built (2026-10-18): 27a (registry and payment gateways), 27b (shipping methods, live rates) and 27c
(pickup points, shipments, labels, tracking); the add-on guide is docs/extensions.md, which is the reference.
Changes from the plan below:

- The registry gives add-ons a log and the shop's orders (`log()`, `orders()`), not an HTTP client, a clock or a
  key-value store: add-ons use WordPress's own for those.
- Quoting is part of `ShippingMethod` (`quote()`), not a `QuotesRates` capability. There is no "Test the
  connection" button: `isReady()` says whether a method can quote with its settings.
- The rate table stays the carrier's own, outside the registry; the plugin's payment gateways register through
  it like an add-on's.
- Tracking: every hour the shop asks for the shipments on their way. The first scan ships an order in
  Processing; delivered completes it from Processing or Shipped; a shipment coming back adds a note for the shop.
- No contract test suite for add-ons yet: the plugin's own tests run a fixture add-on (`TestAddOn`,
  `TestShipMethod`, registered in tests/bootstrap.php) through checkout, the order screen and the emails.

## Why

The old plugin let add-ons (the premium shipping and payment integrations) extend `standart_shipping` and the
payment base class, register through filters such as `register_shipping_class`, and bind a carrier row to its
class. The rebuild has contracts (`PaymentGateway`) but only inside its compiled container, and the release
build prefixes every library (Doctrine, Symfony, even `Psr\…` become `OrillaCart\Vendor\…`). So today no other
plugin can add a payment gateway or a shipping method, and a contract that names a library type could not be
implemented from outside at all.

## Shape

- **Contracts in `OrillaCart\Extension\…`**: interfaces and readonly DTOs using plain PHP types and the plugin's
  own classes, never a library type. This namespace is the only one add-ons may depend on; it is versioned
  (`OrillaCart\Extension\Version::CURRENT`) and changes only by adding. Everything else stays internal.
- **Registration**: once the plugin has loaded, it fires `do_action('orillacart_extensions', $registry)`.
  An add-on calls `$registry->shippingMethod(new DpdMethod(...))`, `$registry->paymentGateway(...)`.
  Ids are unique; a clash is logged and the later one is skipped, so one broken add-on does not break the shop.
- **What add-ons get from the plugin**, through the registry: a logger, an HTTP client (WordPress HTTP API
  behind a small interface), the clock, money and address DTOs, and a key-value store for their own data
  (tokens, label references) kept with the plugin's tables.
- **Settings**: a method declares its settings as a schema (text, secret, choice, yes/no, number). The React
  admin renders it by itself, as the payment screen does for gateways now; secrets are never sent back to the
  browser. A "Test the connection" button calls `check(settings)` when the method offers one.
- **Built-in methods use the API too**: the rate table, cash on delivery, bank transfer, cheque and PayPal
  register through the same registry, so the API is exercised by every test.

## Shipping methods

`ShippingMethod`: `id()`, `name()`, `settings()`, `isReady(settings)`, and the capabilities it implements:

| capability | what it does | where the shop uses it |
|---|---|---|
| `QuotesRates` (required) | a shipment request (items with weight and size, destination, subtotal, currency) to rates (name, price, taxable, delivery estimate) | checkout and order editing, through `ShippingQuotes` |
| `OffersPickupPoints` | offices and lockers near an address, by id, name, address and hours | a picker at checkout; the choice is stored on the order and sent with the shipment |
| `CreatesShipments` | create a shipment for an order (parcels, cash on delivery amount, pickup point), return its number, label (PDF) and tracking link; cancel it | order screen actions: "Create shipment", "Print label", "Cancel"; tracking in the customer's emails and account |
| `TracksShipments` | the shipment's current status | a scheduled task updates it and can mark the order shipped or completed |

Carriers get a `method` (none: the carrier's own rate table, which stays built in because its rates live in
the shop's tables) and that method's settings, and whether its prices are taxed and by which group. A DPD carrier
keeps its name and position in checkout, but DPD prices its options. Rates of a live method are not stored;
checkout keeps the chosen quote with the order (method id, service code, price), as it keeps the rate today.

## Payment gateways and refunds

The `PaymentGateway` contract moved to `OrillaCart\Extension\Payment`, its settings as a schema. Customers
coming back and webhooks go through routes for every gateway (`/payments/{gateway}/return|cancel|webhook`):
`HandlesReturns` names the payment a return is about and, while it is open, its outcome; `HandlesNotifications`
checks a notification came from the provider and gives the outcome. The shop settles each payment once and
holds a completed one whose amount does not match. PayPal is built on these. Item 24's `RefundsPayments` capability joins it, so
PayPal and premium gateways refund through the same contract.

## Tests

- A contract test suite per capability that an add-on can run against its own implementation.
- A fixture add-on in the integration tests that registers a shipping method with every capability, to prove
  the checkout, order screen and emails use them without knowing the add-on.
- `bin/check-build.php` checks that nothing in `OrillaCart\Extension` names a prefixed library class.

## Questions for later

- Which premium add-ons there were (DPD, Speedy, Econt, others), to check the contract against what they did,
  especially labels and cash on delivery collected by the courier.
- Licensing and updates of premium add-ons are their own business; the API does not handle them.
