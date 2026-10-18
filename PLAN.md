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
| 🔄 | 21 | Storefront filtering and search on an index fed by a database queue (`FOR UPDATE SKIP LOCKED`, workers or cron); database or OpenSearch behind one interface ([design](docs/designs/21-catalog-search.md), approved) | |
| ✅ | 21a | ↳ Search index, queue and workers (cron, end of request, WP-CLI), MySQL engine; the shop page searches, sorts and filters on it | 9ae4f66 |
| 🔄 | 21b | ↳ Filters the shop defines, facet counts, price and stock filters, Search block | |
| ⬜ | 21c | ↳ OpenSearch engine, queue status and rebuild in the settings | |
| ✅ | 25 | Orders list actions (raised 2026-10-18): create an order by hand in the admin (customer or guest, items with options, addresses, shipping, status; stock taken like checkout), and delete from the list (row and bulk actions; today only from an order's page, and only cancelled, failed or refunded ones); the customer is emailed only when the shop ticks "Email the customer" (asked 2026-10-18, in 27b's commit) | e634242 |
| 📝 | 26 | Carrier integrations (raised 2026-10-18): a contract a carrier module implements (live rates, pickup points/offices, shipments with labels and tracking), with DPD as the first. The old plugin had none, only its pluggable `methods/` folder holding the standard carrier; design needed (which DPD API and country, what the shop does with labels) | |
| ✅ | 27 | Public extension API for add-ons (agreed 2026-10-18): stable contracts in `OrillaCart\Extension`, an `orillacart_extensions` registry; shipping methods (rates, pickup points, shipments with labels and tracking), payment gateways and refunds on it; built-in methods use it too. Order of work: 25, 27, 21b, 21c, then 26 and the rest | [design](docs/designs/27-extension-api.md) |
| ✅ | 27a | ↳ `OrillaCart\Extension` namespace, registry (`orillacart_extensions`), payment gateways on it (returns and notifications for every gateway, PayPal rebuilt on it), setting types, add-on guide (docs/extensions.md) | 5696987 |
| ✅ | 27b | ↳ Shipping methods: carriers get a method (rate table or an add-on's), live rates at checkout and in order editing | e1e2461 |
| ✅ | 27c | ↳ Pickup points at checkout, shipments with labels, tracking and cancelling from the order screen | 930386c |
| 📝 | 22 | Coupon codes: by rules (minimum amount, dates, usage limits, per customer) or for specific products and categories | [design](docs/designs/22-coupons.md) |
| 📝 | 23 | Dynamic prices (quantity tiers, customer groups, scheduled rules), bundles and sets | [design](docs/designs/23-dynamic-prices-bundles.md) |
| 📝 | 24 | Refunds through the payment methods: a contract the gateways that can refund implement (PayPal's refund API); offline methods recorded by hand; full and partial | [design](docs/designs/24-refunds.md) |

## Waiting on others

- PayPal against the real sandbox: needs sandbox credentials.

## Deliberately not carried over

Per-file prices and choosing which files to buy; download limits multiplied by quantity; editing the country list (ISO
3166 comes complete); SEO fields (SEO plugins do this); image size and template settings (WordPress image sizes and
theme templates); the old and/or/not shipping operators (replaced by item 17); shipping groups that affected nothing
(replaced by item 15).
