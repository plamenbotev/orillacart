# OrillaCart 2 — plan

The rebuild of OrillaCart on branch `rebuild`. Nothing is pushed until all of it is done.

Status: ✅ done · 🔄 in progress · ⬜ to do · 📝 needs a design approved before building

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
| ✅ | 22 | Coupon codes: a percentage (with a cap), an amount off the order or each item, or free shipping; for the whole order or some products, categories and brands, with exclusions and sale items left out; dates, order size, item count, countries; uses in all and per customer, first orders only; codes combine when each allows it. Taken off before tax; uses counted under a lock when the order is placed and given back when it is cancelled, fails or is deleted; orders keep code and amount | (this commit) |
| 📝 | 23 | Dynamic prices (quantity tiers, customer groups, scheduled rules), bundles and sets | [design](docs/designs/23-dynamic-prices-bundles.md); questions answered 2026-10-18 |
| ✅ | 24 | Refunds through the payment methods: `RefundsPayments` in the extension API (PayPal refunds captures and records refunds made in its dashboard from the webhook); offline methods recorded by hand; full and partial, by items or an amount alone, with restock; the customer is emailed; paid orders leave only by refunding, not by cancelling (raised 2026-10-18) | 82e5059 |

## Waiting on others

- 26 Carrier integrations such as DPD (2026-10-18): not now. DPD was the example that shaped the extension API (27); couriers' add-ons come later, on that API.

- PayPal against the real sandbox: needs sandbox credentials.

## Deliberately not carried over

Per-file prices and choosing which files to buy; download limits multiplied by quantity; editing the country list (ISO
3166 comes complete); SEO fields (SEO plugins do this); image size and template settings (WordPress image sizes and
theme templates); the old and/or/not shipping operators (replaced by item 17); shipping groups that affected nothing
(replaced by item 15).
