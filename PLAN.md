# OrillaCart 2 — plan

The rebuild of OrillaCart on branch `rebuild`. Nothing is pushed until all of it is done.

Status: ✅ done · 🔄 in progress · ⬜ to do · 📝 needs a design approved before building · ➖ not now

## Phases of the rebuild

| | Phase | Commit |
|---|---|---|
| ✅ | 0. Foundation: PHP 8.4, Symfony DI, Doctrine, release build | 3a2309c |
| ✅ | 1. HTTP kernel, REST routes, settings, events, logging, Twig | f81c7d9 |
| ✅ | 2. Geography and tax | d3d12b3 |
| ✅ | 3. Catalog and inventory | 4191c97 |
| ✅ | 4. Cart, checkout, shipping and payments (cash on delivery, bank transfer, cheque, PayPal REST) | 86f6aeb |
| ✅ | 5. Orders admin, customers, emails, downloads | 4569fd5 |
| ✅ | 6. Storefront (Twig blocks), admin polish, Playwright tests | 7a13d95 |
| ✅ | Mini cart with buttons, like the old cart widget | 7a44166 |

## Parity with the old plugin, and what came up on the way

Agreed on 2026-10-18 after comparing every screen and setting of the old plugin with the rebuild.

| | # | Item | Commit |
|---|---|---|---|
| ✅ | 1 | Attributes and options: drag into order, fold away (saved ones start folded) | 2d1ecde |
| ✅ | 2 | Variations as private child posts of their product: own name, description, image, gallery, weight and size | 26bc195 |
| ✅ | 3 | Stock page: products, variations and options by stockroom; ID, SKU, filters, search by name/SKU/ID; bulk save | 0bad6c2 |
| ✅ | 4 | Currency sign and where it goes | be47876 |
| ✅ | 5 | Catalog only; hidden prices, shop-wide or per product ("ask for the price") | be47876 |
| ✅ | 6 | Countries the shop sells to | be47876 |
| ✅ | 7 | Shop postcode | be47876 |
| ✅ | 8 | Product list: image, SKU, price, order columns; category and brand filters; quick edit of price | f4d31e3 |
| ✅ | 9 | Product order field, and the shop page listing by it | f4d31e3 |
| ✅ | 10 | Price sorts; Products block for featured or sale products only | f4d31e3 |
| ✅ | 11 | Category thumbnail | dfa3397 |
| ✅ | 12 | Stockroom delivery time in days or weeks | 0ebf7d1 |
| ✅ | 13 | Orders: move to another customer or to guest; change the shipping method; edit download allowances | 01e6eb2 |
| ✅ | 14 | Shop manager role: an editor plus the shop; no user, plugin or site settings access | 6460dc8 |
| ✅ | 15 | Shipping classes: one per product (variations share it); a rate carries every item or only the classes it lists | c3b6566 |
| ✅ | 16 | Postcodes on shipping rates: exact (1000), start (SW1*) or numeric range (1000-1999); the checkout sends the postcode | 6e2e887 |
| ✅ | 17 | Regions and postcodes a shipping rate leaves out (replaces the old and/or/not operators) | 612df22 |
| ✅ | 18 | Option prices as a percentage of the product price (on sale when it is; percentages do not compound) | 1ff29eb |
| ✅ | 19 | Downloads sent by the web server (X-Sendfile, X-Accel-Redirect), kept only after a test file went through; shows the server rule otherwise | d22a0d9 |
| ✅ | 20 | Backorders: per product, variations may differ; the fastest stockroom goes below zero; order lines record what is owed; optional notice on the product page, cart, checkout, receipt and emails | 0dc7455 |

## After parity

| | # | Item | Commit |
|---|---|---|---|
| ✅ | 21 | Storefront filtering and search on an index fed by a database queue (`FOR UPDATE SKIP LOCKED`, workers or cron); database or OpenSearch behind one interface ([design](docs/designs/21-catalog-search.md), approved) | a259cf4 |
| ✅ | 21a | ↳ Search index, queue and workers (cron, end of request, WP-CLI), MySQL engine; the shop page searches, sorts and filters on it | 9ae4f66 |
| ✅ | 21b | ↳ Filters the shop defines, facet counts, price and stock filters, Search block | 739feb1 |
| ✅ | 21c | ↳ OpenSearch engine, queue status and rebuild in the settings | a259cf4 |
| ✅ | 25 | Orders list actions (raised 2026-10-18): create an order by hand in the admin (customer or guest, items with options, addresses, shipping, status; stock taken like checkout), and delete from the list (row and bulk actions; today only from an order's page, and only cancelled, failed or refunded ones); the customer is emailed only when the shop ticks "Email the customer" (asked 2026-10-18, in 27b's commit) | e634242 |
| ✅ | 27 | Public extension API for add-ons (agreed 2026-10-18): stable contracts in `OrillaCart\Extension`, an `orillacart_extensions` registry; shipping methods (rates, pickup points, shipments with labels and tracking), payment gateways and refunds on it; built-in methods use it too. Order of work: 25, 27, 21b, 21c, then the rest | [design](docs/designs/27-extension-api.md) |
| ✅ | 27a | ↳ `OrillaCart\Extension` namespace, registry (`orillacart_extensions`), payment gateways on it (returns and notifications for every gateway, PayPal rebuilt on it), setting types, add-on guide (docs/extensions.md) | 5696987 |
| ✅ | 27b | ↳ Shipping methods: carriers get a method (rate table or an add-on's), live rates at checkout and in order editing | e1e2461 |
| ✅ | 27c | ↳ Pickup points at checkout, shipments with labels, tracking and cancelling from the order screen | 930386c |
| ✅ | 28 | Background jobs with an overview (asked 2026-10-18): one queue for all async work (search indexing first, then emails, tracking, webhooks), lanes, retries, failed jobs to retry or delete, workers with heartbeat, and a Shop → Background jobs screen (waiting, running, failed, workers, history) | [design](docs/designs/28-background-jobs.md); emails on jobs now, done jobs kept as hourly counts (decided 2026-10-18) |
| ✅ | 28a | ↳ The job system (lanes, unique jobs, batches, retries with back-off, failed jobs, hourly counts, workers in a table) with `wp orillacart jobs`; the search index runs on it | 0a68945 |
| ✅ | 28b | ↳ Order emails as `mail.order` jobs (retried after 1, 4, 16, 64 minutes, then failed; resending from the order stays immediate) and shipment tracking as `shipping.track` jobs, one per shipment | e9bf28c |
| ✅ | 28c | ↳ Shop → Background jobs screen, refreshed every 5 seconds: lanes, workers (restart), whether anything runs the queue, failed jobs to retry or delete (one, chosen or all, with payload), 24-hour chart and 30-day totals, scheduled jobs | f7eac24 |
| ✅ | 22 | Coupon codes: a percentage (with a cap), an amount off the order or each item, or free shipping; for the whole order or some products, categories and brands, with exclusions and sale items left out; dates, order size, item count, countries; uses in all and per customer, first orders only; codes combine when each allows it. Taken off before tax; uses counted under a lock when the order is placed and given back when it is cancelled, fails or is deleted; orders keep code and amount | 4c13f13 |
| ✅ | 23 | Dynamic prices (quantity tiers, customer groups, scheduled rules), bundles and sets | [design](docs/designs/23-dynamic-prices-bundles.md); questions answered 2026-10-18 |
| ✅ | 23a | ↳ Customer groups (Shop → Customers) and price rules (Shop → Price rules): prices by quantity, for groups, on products, categories or brands, between dates; the lowest wins; the product page shows the quantity table; the cart, checkout, listings and search index use them | 80aa674 |
| ✅ | 23b | ↳ Automatic discounts without a code (Shop → Price rules): order-level discounts such as 10 % off over 100 or free shipping over 50, on the coupon engine with no code; customers see the description | dee2d41 |
| ✅ | 23c | ↳ Bundles and sets: any product can be made of other products or variations, at its own price or its items' sum less a percentage; sold while every item is, taking each item's stock; one line in the cart and order with its contents listed. | ec60250 |
| ✅ | 23d | ↳ Bundle choices: an item may offer several variations for the customer to choose, and may be optional (a fixed price covers the required items; optional ones add their own). The product page asks the server for the price and stock of the chosen combination; the picks travel with the cart line to the order, and the order editor's picker asks for them | c81cc5c |
| ✅ | 24 | Refunds through the payment methods: `RefundsPayments` in the extension API (PayPal refunds captures and records refunds made in its dashboard from the webhook); offline methods recorded by hand; full and partial, by items or an amount alone, with restock; the customer is emailed; paid orders leave only by refunding, not by cancelling (raised 2026-10-18) | 82e5059 |
| ✅ | 29 | Reports (Shop → Reports): sales, products, categories, coupons and customers over any days against the days before or a year before, by day, week or month, with charts and CSV. Fact tables kept per order by background jobs, so reports never read the orders; refunds count on their own day; `wp orillacart reports rebuild` | [design](docs/designs/29-reports.md); b288d39 |


## Found by comparing the old plugin's hooks and features (2026-10-18)

| | # | Item | Commit |
|---|---|---|---|
| ✅ | 31 | Small gaps: product reviews (comments on products); translations (`.pot` with the templates' strings, `languages/` loaded for PHP and scripts); multisite (network activation sets up every site, new sites are set up, a deleted site's tables go); shop sign-ups checked by add-ons (`orillacart_registration_errors`); a deleted WordPress user's orders become guest orders, their carts and group places go. Found on the way: foreign key names are unique per database, so each site prefix gets its own; the cart printed problem codes (fixed in 30) | f8b37b5 |
| ✅ | 30 | Add-on hooks the old plugin had and the rebuild lacked (extension API version 2): price adjusters after the shop's rules; cart rules (refuse a line or the cart) and cart actions (added, changed, removed); checkout fields add-ons ask, kept with the order with their labels, shown on the receipt, in emails and on the order screen, which corrects them; page slots in the product, cart, checkout, receipt, account and email templates; email attachment and header filters; add-on template folders; order screen actions and `orders()` for reading orders; addresses in their country's layout. The cart now shows readable problems ([design](docs/designs/30-addon-hooks.md)) | f8d84d1 |

## Production audit (2026-10-18)

Six reviews over every area, each finding checked against the code. Priority: P1 = loses money, data or orders;
P2 = wrong or fragile under real traffic; P3 = polish, operations and tests. All of it is to be done. Items marked
(decide) need a decision first.

### Bugs and gaps found

| | # | P | Item | Commit |
|---|---|---|---|---|
| ✅ | 32 | P1 | Every order state transition in one transaction: `cancel`, `fail`, `delete`, `cancelAbandoned`, `refund`, `changed`, `releaseStock` and `Refunds::completed` wrap stock give-back, save, coupon release and events in `Transactions::run`. Today `giveBack` commits on its own before `orders->save` (a failed save restocks twice on retry) and `Discounts::release` locks coupons with no transaction open, so cancelling, failing or deleting any coupon order throws `TransactionRequiredException` (the in-memory test repository ignores the lock). Integration test: cancel a coupon order with the real repository | 2bc5e44 |
| ✅ | 33 | P1 | Checkout placed once: the cart row locked `FOR UPDATE` and cleared inside the placement transaction; an idempotency key on `POST /checkout` kept on the order (unique) so a replay returns the order already placed instead of a second order with stock taken twice | 75307fc |
| ✅ | 34 | P1 | Cart ownership: a token cart that belongs to another customer is ignored (a fresh token is issued); the cookie goes at `wp_logout`/`clear_auth_cookie` and the token is rotated at login; a guest cart is merged into the account's cart at login (quantities summed, coupons kept) instead of orphaning the account cart | e84787a |
| ✅ | 35 | P1 | The cart survives a failed payment start: cleared only after the payment started (or for offline methods); `PaymentProviderUnavailable` leaves the customer with the cart and a message; pending and failed orders get a pay-again path (item 83; the cart part is done, the pay-again page is 83) | 75307fc |
| ✅ | 36 | P1 | PayPal approval webhook: resolve the reference to a payment the shop knows before capturing (two-phase `HandlesNotifications`), so a shared PayPal app cannot capture another site's orders. A capture that lands on a cancelled or failed order is refunded/voided through `RefundsPayments` automatically (or refunds are allowed on orders with a completed payment regardless of status); the payment window grows from 1 h to at least PayPal's 3 h | 120403a |
| ✅ | 37 | P1 | Settlement under a lock: `returned()`/`notified()` load the payment `FOR UPDATE` inside a transaction, so the return URL and the webhook cannot both complete it and email twice; `Refunds::send` re-reads the refund in the transaction so a webhook arriving mid-call cannot record the refund (and restock) twice; `Order::markPaid` on an already paid order with a new transaction id adds a note and keeps the id instead of silently returning false | f967324 |
| ✅ | 38 | P1 | Stock locked in one call per order, items sorted by kind and id, so two carts with the same items in another order cannot deadlock; `DeadlockException` retried once and otherwise shown as a readable checkout error, not a 500 | c94c252 |
| ✅ | 39 | P1 | Schema upgrade: triggered on every request (late `init`; `plugins_loaded` is too early for rewrite rules and translations) under a lock (`GET_LOCK` or an atomic `add_option` sentinel), `set_time_limit(0)`, `\Throwable` caught and logged, the failed version stored and shown as an admin notice instead of a fatal on every wp-admin page; `wp orillacart migrate` documented as the recovery | 772366c |
| ✅ | 40 | P1 | Manual orders pass `discountTotal` and `discounts` from the bill (today an automatic discount makes `Order` throw "not the sum of its parts"); `OrderQuoteView` lists the discount | 1f262f7 |
| ✅ | 41 | P1 | `hasStandingOrder` leaves out the order being edited, so editing an on-hold order keeps a first-order coupon; `DiscountRequest` carries the order id; test | 46dd604 |
| ✅ | 42 | P1 | Emails sent once: a sent marker per order, kind and note/refund before `wp_mail`; `SendOrderEmails` catches `\Throwable` per job so one bad job does not re-run its batch mates (today a batch exception re-sends every earlier email); the first batch exception is logged | 3ab9a3a |
| ✅ | 43 | P1 | Add-on isolation on the money path: `\Throwable` caught around gateway `start`/`returned`/`notified`/`refund` and carrier `createShipment`/`label`/`cancelShipment` (quotes already do), logged, treated as provider unavailable; a refund that threw gets an error so it no longer blocks further refunds of the order | 59d6046 |
| ✅ | 44 | P1 | Provider-pending refunds get their own status and do not touch the order until confirmed; a reconciliation job polls held captures and pending refunds; `webhook_id` required for a live account; refund-failed, `PAYMENT.CAPTURE.REVERSED` and dispute events flag the order "Needs attention" with a note and email the shop (decided 2026-10-18; paid orders cannot go on hold) | 6fc4f5a |
| ✅ | 45 | P1 | Paid is not a status: `paidAt` set by "mark paid" (or the gateway) independently of Processing/Shipped, so cash-on-delivery and cheque orders ship unpaid, the courier gets the COD amount, reports count revenue when the money exists, and the status list no longer implies payment Completing an unpaid order records the payment (decided 2026-10-18) | 702ac88 |
| ✅ | 46 | P1 | Storefront `api()` turns a rejected `fetch` into `{ok:false, code:'network'}` with a message; every caller re-enables its button in `finally`; `reloadRegions` checks `response.ok`; coupon removal and account forms check their result; cart quantity changes chained with one reload after the last, focus kept, a message for quantities under 1 | 01e410c |
| ✅ | 47 | P1 | Admin order screen: every card disabled while its request runs (status, note, customer details, send email, delete); refunds through the provider ask for confirmation; one confirm dialog for every destructive action instead of `window.confirm` here and inline notices there | 96d4759 |
| ✅ | 48 | P2 | Public WordPress actions (`orillacart_order_placed`, `orillacart_stock_changed`, …) buffered until the outermost transaction commits, so add-ons never act on an order that is rolled back | 2bc5e44 |
| ✅ | 49 | P2 | Deleting orders: only cancelled, failed or refunded ones (as item 25 said), never with a booked consignment; foreign keys from `payments` and `refunds` (restrict) and `consignments` (cascade) to `orders`; `SettleOrdersByPayment` and `Refunds` catch `OrderNotFound` from a late webhook and log it | 39326d5 |
| ✅ | 50 | P2 | Refund money: the ceiling is the amount paid, not the order total; provider refunds capped at the payment; each refund records tax lines (pro rata of the refunded lines and shipping) and a per-line refunded amount, and reports and VAT use them; a completed capture is compared with the order's current total; editing an order with an open provider payment is refused or re-syncs the payment amount | 277d2bc |
| ✅ | 51 | P2 | A full refund by amount alone keeps `stockTaken`, so the items can still be restocked later; a full refund recorded from the provider's dashboard asks whether to restock | 3e02c3c |
| ✅ | 52 | P2 | Discount rules: automatic discounts always combine (or form their own layer after the codes) and the refusal says so; `minSubtotal`/`maxSubtotal` judged on the undiscounted items subtotal, not what is left after earlier codes; per-customer limits counted by customer id when the email is not yet known; the admin date inputs convert from the shop's time zone, not the browser's | 2405ef8 |
| ✅ | 53 | P2 | Tax before an address is known: fall back to the shop address (or the customer's saved address) and label the cart and summary "estimated"; the cart shows the tax and shipping estimate; settings warn when no default tax group exists (nothing is taxed silently) | 8d8b216 |
| ✅ | 54 | P2 | Price decimals come from the currency (ISO 4217 minor units; reuse PayPal's table) with an override for display only; the pair validated in settings (JPY with two decimals makes PayPal refuse every order today) | f86bae0 |
| ✅ | 55 | P2 | `PlaceOrder` carries the total the customer saw; a different total is refused with `orillacart_cart_changed` and the summary re-rendered | 6b0bb7a |
| ✅ | 56 | P2 | Rounding: unit prices rounded to the currency's decimals at quote time after all adjustments; the Twig `money` filter formats through `Money::format`, never `(float)`; decide whether `order_lines.tax` stores the rounded line tax or its share of the billed tax; `Money::proportion` in integer math; overflow guards in `times`/`add` against `Money::MAX`; the order-amount coupon's rounding remainder goes to the largest line; one `Percentage` validator for tiers, bundles and coupons | d14a485 |
| ✅ | 57 | P2 | Reindex scope: `PriceRulesChanged` names the products and groups it touches (only shop-wide rules queue everything); `QueueTermProducts` queues only when name or parent changed, and hooks `delete_term` after the row is gone; `ProductLookup::bundlesHolding` through a `bundle_items` table (or `JSON_CONTAINS`) instead of hydrating every bundle on every stock change | 774973d |
| ✅ | 58 | P2 | Jobs outside the request's context: handlers run under `switch_to_locale` (site or order locale) and `wp_set_current_user(0)` so an admin's profile language never reaches a customer's email; the end-of-request runner only when `fastcgi_finish_request` exists (else cron), with `set_time_limit(0)`; each handler declares its lock time or extends `locked_until` from the heartbeat; a unique push updates the payload | f1402bb |
| ✅ | 59 | P2 | OpenSearch truth: "ready" only when nothing is due, running or failed for `search.index`; bulk delete errors checked; rebuild removes documents of products no longer in the catalog; `product_id` tiebreak on every sort; indexed prices are guest prices — documented, or group-priced products left out of price filters | 886e67e |
| ✅ | 60 | P2 | Emails: subjects rendered without HTML escaping; `<html lang>` from the locale; a text/plain alternative; resend limited to status emails (a resent note or refund email needs its note or refund) | 51ae39b |
| ✅ | 61 | P2 | Abuse controls on public routes: filterable per-IP/per-cart counters on `POST /accounts`, `/cart/coupons`, `/checkout` and `/session`; a neutral answer when the email already has an account; a captcha/honeypot hook; usernames not the email's local part; optional email confirmation | 0043992 |
| ✅ | 62 | P2 | Receipts and payment returns: `Cache-Control: no-store` and `Referrer-Policy: no-referrer` on receipts; an HMAC in the PayPal return and cancel URLs, and cancel only when PayPal itself reports the order not approved; order keys hashed at rest like cart tokens; by-key lookups rate-limited; decide whether key access expires | 772073f |
| ✅ | 63 | P2 | Secrets: gateway secrets, the OpenSearch password and the PayPal token encrypted with a salt-derived key (token transient not autoloaded); secrets can be cleared, not only replaced; a length limit on text settings; the choice error lists keys, not labels | 4ee4036 |
| ✅ | 64 | P2 | Uninstall: a "remove all shop data" setting (default off) read by `uninstall.php`; the downloads folder cleanup deletes only files the plugin wrote and never removes a folder it did not create (`ORILLACART_DOWNLOADS_DIR` may be shared); the Twig cache folder removed; the deny files written on every check and the HTTP probe run whenever the folder is under `ABSPATH` | 8392b15 |
| ✅ | 65 | P2 | Parcels: sides are the largest unit's (or a stacking rule), volume stays the sum, dimensional weight added; weight and size units stored with rate ranges or converted when the setting changes; `Shipment` offers unit conversion to add-ons | 700c2b6 |
| ✅ | 66 | P2 | Shipments drive the order: first on-the-way tracking (or booking, decide) moves it to Shipped, delivery completes it from Processing or Shipped, a returned shipment is noted on the order | 598031d |
| ✅ | 67 | P2 | Smaller transaction gaps: free orders marked paid inside the placement transaction; manual orders' note and hold inside theirs; address changes refused after Shipped or with a booked consignment (email and phone stay editable) | 7cc065d |
| ✅ | 68 | P2 | Admin stale responses: `AbortController`/current-flag guards in the Orders, Stock, Jobs, Customers lists, `NewOrder` (the quote checked against its key before use) and the `OrderDetail`/`RefundsCard` effects; contact and items cards re-synced from a freshly returned order | 71927a9 |
| ✅ | 69 | P2 | Admin forms: an unsaved-changes guard (settings, shipping, payments, tax, stock, coupons, price rules; the product editor's variations and stock panels mark the post dirty); one money control (decimal comma, inline validation, step from the currency, integer suggestions); pickers search and page past 100 terms/pages/products; admin addresses use the storefront's country and state selects; quick-edit price errors shown | 2b3def0 |
| ✅ | 70 | P2 | Query cost: customers list counts orders and group places in two grouped queries; `CustomerGroups::groups` counts without hydrating members; SKU uniqueness across products and variations in one statement (shared `skus` table) | 11a321d |
| ✅ | 71 | P3 | Roles and hosts: the shop manager role without `unfiltered_html`; the OpenSearch host validated (scheme, host, no credentials in the URL) | b003625 |
| ✅ | 72 | P3 | Webhook plumbing: provider unavailable answered 503 with `Retry-After`, not 422; malformed JSON bodies answered 400; PayPal event ids stored against replay; refunds matched by `custom_id`/request id, not amount | 1d81121 |
| ✅ | 73 | P3 | PayPal order payload: item lines with a breakdown, the shipping address for physical goods (seller protection), `custom_id` = order number; "mark paid" on a held payment copies its transaction id and completes the payment row | 2557a8b |
| ✅ | 74 | P3 | Add-on edges: each `orillacart_extensions` callback run on its own and the failing one logged (today one throwing add-on drops every later one); live-rate failures cached for a minute; field checks and price adjusters may declare fail-closed, and failures reach the shop as an order note, not only the log | 8dd3da1 |
| ✅ | 75 | P3 | Downloads: counted on delivery (or with a tolerance), `Accept-Ranges` for large files | c288f45 |
| ✅ | 76 | P3 | Reports and jobs screens: year-before buckets aligned (leap days); the rebuild job unique; job history and date presets in the shop's time zone (the server gives "today") | 9a5c9d4 |
| ✅ | 77 | P3 | PII hygiene: failed-job payloads and error strings without emails or note text; `report_orders.customer_key` erasable (with item 86) | d2acdf3 |
| ✅ | 78 | P3 | Design docs brought in line with the code: 24 (`refunded_amount`, owed-first, pending check), 27 (tracking and status, contract tests), 28 (90 days, 5 attempts, no `finished_at`), 21 (`ProductChanged`, no `index_jobs`), extensions.md (the cart is kept) | 229a7a7 |
| ✅ | 79 | P3 | Operations: a persistent Doctrine metadata cache under `wp-content/cache/orillacart`; Site Health tests and debug info (pending migrations, cron lag and workers, downloads folder, Twig cache, PayPal webhook); the Jobs screen warns when `DISABLE_WP_CRON` is set and no worker reports; undefined Twig variables logged in production; a `block.json` per block | a9f8842 |
| ✅ | 80 | P3 | Tests and CI: the release job waits for the integration job; an OpenSearch job in CI; tests for cancelling a coupon order (real repository), two checkouts at once, return and webhook at once, a manual order with an automatic discount, a first-order coupon on edit, a gateway `start()` that throws, the COD booking amount, parcel sides against size limits, `WordPressPayPalApi`, email batches, duplicate unique pushes, term queueing, report alignment, the document builder; Playwright for the Reports, Coupons, Price rules, Shipping, Payments, Tax and Customers screens; multisite, an upgrade on populated tables, uninstall | fad603f, 49d3920, 0ed0a0f, c11a94c |
| ✅ | 81 | P3 | Storefront and lists: a `<noscript>` notice on the product, cart and checkout pages (POST fallbacks where cheap); the orders list sorts, filters by date, customer, payment and total, and keeps its state in the URL; the untranslated admin fallback message; `readme.txt` changelog, screenshots and a real tested-up-to | 1e091f4 |
| ✅ | 82 | P3 | Smaller logic (decided 2026-10-19): a backorder cap per product and variation (empty: unlimited); the cart cookie `Secure` from `is_ssl()` and refreshed on every write; percentage options on the ruled price; quantity tiers counted across all lines of a product; a bundle taxed per component, its price split over its items; price rules only lower prices (as now); billing address columns `NOT NULL` with their own mapping | b40198c, fbecb6a, c228480, b899d9b, e1178d3, 505f32e |

### Missing for an e-commerce system

| | # | P | Item | Commit |
|---|---|---|---|---|
| ✅ | 83 | P1 | Pay now: pending, failed and manual orders get a payment page (choose a method, pay) linked from the receipt, the account and the emails; replaces "place a new order" as the only recovery Any unpaid order, any method offered (decided 2026-10-18) | 2a4c21e |
| ✅ | 84 | P1 | Invoices: a gapless invoice number taken at payment under a lock (order ids have gaps), a PDF invoice and credit notes with tax lines, attached to emails and downloadable from the account and the order screen Printable HTML pages, seller details in Shop → Settings → Invoices (decided 2026-10-18); paid orders cannot be deleted | b5e63b4 |
| ✅ | 85 | P1 | Tax for the EU: prices entered and shown including tax (setting and "incl./excl." display), VAT number validation (VIES) with reverse charge, OSS destination VAT for digital goods, the lowest price of the last 30 days on sale displays (Omnibus) Same gross price everywhere; VIES down charges VAT (decided 2026-10-18) | 9b53155, a2453f0, 9e947a9, 6c255b5 |
| ✅ | 86 | P1 | GDPR: WordPress personal-data exporters and erasers, an "anonymise order" action, privacy policy content, erasable report facts and job payloads | 37a5be3 |
| ➖ | 87 | P1 | Not now (decided 2026-10-18: left to an add-on on the extension API). Card payments: Stripe (or a hosted-checkout gateway with 3DS/SCA) on the extension API, with dispute and chargeback webhooks; saved payment methods | |
| ✅ | 88 | P2 | Emails the shop controls: subject and body per kind edited in the admin, preview and test send, an account/welcome email (none is sent today), an on-hold payment reminder, a backorder notice, tracking-number-added, admin alerts for failed payments and refunds and low stock, the invoice attached. Built as docs/designs/31-emails.md: whole templates in sandboxed Twig, invoice PDFs by dompdf, the reminder once after N days, low-stock thresholds on the shop, stockrooms and products (decided 2026-10-20) | 3c7745a ffc871c 8ba96bd d656aa0 4108260 ff96e2b 11a563a 95df6ff 1effa21 |
| ✅ | 89 | P2 | Import and export: products from and to CSV; orders exported (CSV/JSON) from the list; bulk price and stock editing and product duplication in the product list. Built as docs/designs/32-import-export.md: columns add-ons extend (extension API 4) and the shop manager chooses and orders in the admin (decided 2026-10-20) | 2905e42 7b798c9 8cdd77c d1d5370 af36ddf 151348a 6f8c278 |
| ✅ | 90 | P2 | Stock: a `stock_movements` ledger (item, stockroom, delta, order, reason, time) feeding per-stockroom history and reports; low-stock thresholds with alerts (done in 88); "tell me when it is back"; unpaid on-hold orders expire after N days (setting). Built as docs/designs/33-stock.md; expiry off until the shop sets its days | e4009f5 53b9f57 bc88acd 7b15fb8 1537324 |
| ✅ | 91 | P2 | Abandoned carts: guest carts tied to an email at checkout start, reminder emails as jobs, a report | 5937b41, d1d5e9d, 5503a14 |
| ✅ | 92 | P2 | Fulfilment: returns/RMA; partial and multi-package shipments with a tracking number per parcel; order-level fees (COD fee, payment fee) on the extension API; "spend X more for free shipping"; table rates by item count; delivery date choice; local pickup with hours; address validation | 1f0bd20, 899a942, 308775f, 1182b95, f996dc5, 51e5e15, bb25037, 9bf4b32, 181be6b, 9341287 |
| ✅ | 93 | P2 | Customer account: an address book, guest order lookup by number and email, reorder, customer notes and lifetime value on the customer screen, newsletter opt-in at checkout | 0d90540, 046cb35, 74cd95f, d61b940, 3a0f5bc, 2706960 |
| ⬜ | 94 | P3 | SEO and merchandising: Product JSON-LD and Open Graph, sitemap entries with images, related/upsell/cross-sell, reviews rendered by the plugin with ratings and a verified-buyer mark, wishlist, recently viewed, product tags indexed, analytics events (view, add to cart, purchase) as hooks and a dataLayer; archive and category block templates and a classic-theme fallback | |
| ⬜ | 95 | P3 | Operations: a setup wizard, a status screen (with item 79), a dashboard widget and admin-bar order count, an audit log of settings changes, outgoing webhooks and API keys for integrations, settings export, REST route documentation, a sample-data importer, `wp orillacart export|anonymise|doctor` | |
| ⬜ | 96 | P3 | Commerce settings: a shop-wide minimum order amount (and per rate), gift cards and store credit, buy X get Y and free gifts, rounding settings, multi-currency (orders already store theirs), compound and postcode-level tax rates | |
| ⬜ | 97 | P3 | Combining options: price rules say whether coupons apply on top of their price and whether they stack with other price rules (today the lowest single price wins); decide which side owns the coupon ↔ price rule choice | |

## Waiting on others

- 26 Carrier integrations such as DPD (2026-10-18): not now. DPD was the example that shaped the extension API (27); couriers' add-ons come later, on that API.

- PayPal against the real sandbox: needs sandbox credentials.

## Deliberately not carried over

Per-file prices and choosing which files to buy; download limits multiplied by quantity; editing the country list (ISO
3166 comes complete); SEO fields (SEO plugins do this); image size and template settings (WordPress image sizes and
theme templates); the old and/or/not shipping operators (replaced by item 17); shipping groups that affected nothing
(replaced by item 15).
