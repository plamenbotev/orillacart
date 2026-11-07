# 55. High volumes

Status: built (2026-11-07). PLAN items 312–316.

What breaks first when a shop has hundreds of thousands of orders, tens of thousands of products and a storefront
under real traffic, found by reading the queries and the schema rather than by guessing, and the fixes for each.
Nothing here redesigns a table.

## What was already right

Jobs are claimed with `FOR UPDATE SKIP LOCKED`, finished jobs are deleted, and the jobs table is the outbox inside
the order's transaction. Checkout locks the cart, the stock rows in sorted order, the coupons and gift cards, and
retries on a deadlock. Reports read projected facts tables, not the orders. Search has FULLTEXT indexes and an
OpenSearch engine with a fallback. Exports page by key. Carts, emails, failed jobs, payment events and the change
log have retention. The "first order" check of the customer reports, a correlated EXISTS, is answered by the
`(customer_key, placed_at)` index and stays.

## The order search (312)

The admin's free text ran `LIKE '%text%'` over fifteen columns joined by OR, plus an EXISTS over the lines: a scan of
every order per keystroke. The order now gathers what it is found by into one column, `words`: its email, VAT
number and transaction id, both addresses' names, phone, street, city and postcode, and each line's name in the
customer's language and the shop's, and SKU. The order recomputes it at each write that changes one of them
(placed, contact changed, lines changed, paid, anonymised), so it is never stale and nothing else has to remember.
A FULLTEXT index on it answers the search as a boolean `MATCH` of the words as prefixes (`+ada* +lovelace*`), with
the words under three characters, which InnoDB does not index, looked up in the same column. `FullTextQuery`, which
wrote the catalog search's words, moved to `Shared\Persistence` and serves both. The migration fills the words of
the orders placed before it from their columns and lines.

What changed for the user: a search finds words by their start ("guid" finds "Guide", "lovel" finds "Lovelace"),
no longer by any part ("ovela" does not). Numbers and the add-ons' lookups (tracking, invoice, coupon numbers) are
exact, as before.

A person's orders (the account page, personal data exports and erasure, the exemption emails) were every id by
email plus every id by account, merged and sliced in PHP. They are one query with `email = ? OR customer_id = ?`,
ordered and paged by the database, which merges the two indexes. The "bought these products" check for reviews
goes by the new product index on the lines, narrowed to the person, instead of by the person's every order id.

## The indexes (313)

One migration: orders by `placed_at` (filtered alone before, with only the status index's second column); order
lines by `product_id` (the backorder filter, the reviews' check, "orders with this product"); stock levels by
`quantity` (low stock); the search documents by `(original, menu_order, title)` and `(locale, menu_order, title)`,
so the shop's default listing filters and orders by one index without a sort; and the variants' index widened to
`(product_id, in_stock, price)`, so the stock and price conditions of the filter's EXISTS read the index alone.

The widened index changed which row MySQL met first in the facets' `MIN(label)`, and a value spelled "M" by one
product and "m" by another showed either. The label is now the least spelling by bytes, whatever the plan.

## The listing's results shared (314)

A listing page ran the search (two queries, plus one per facet and one for the price range), and the Products and
Product filters blocks of one page already shared the result for the request through the object cache's
per-request group. The index's generation, which told one request's result from the next, moves to a persistent
cache group, and so does the result, for a minute or until the index changes. On a site with a persistent object
cache (Redis, Memcached) every request shares the counting and the facets, which are the costly part; the page of
ids is one indexed query each time. On a site without one nothing changes: WordPress's object cache lives for the
request. The result holds nothing of the shopper: the query names categories, brands, filters, words and language
only.

## History kept for as long as a setting says (315)

Stock movements, past prices (kept for the EU's Omnibus rule) and the records of carts left at checkout only grew.
"Keep history for (days)" on the General settings, a year by default, 90 at the least and ten years at the most,
bounds them: a daily task deletes what is older. Each item's latest price stays whatever its age: it is the price
in effect, which the 30 days before a later price are measured against. Orders, and the facts the reports count,
are not history and stay. Download grants stay too: they are a customer's right from an order, not history.

## The Home screen (316)

Two sales reports, three order counts, the low stock list, the jobs overview and the status checks ran on every
visit to the admin's first page. The overview is kept a minute, as the admin bar's to-do counts already were, per
user, as add-ons may put lines there that depend on who looks, and per day, so the figures do not step back over
midnight.

## Not changed, and why

- **Jobs after the response.** A request runs up to twenty of the jobs it pushed after its response, but only on
  the queues no worker serves: with a worker running, as a shop under load should (`docs/workers.md`), it runs none.
- **Offers on the storefront.** Loading a page of products' offers is several queries, but what they show depends on
  the shopper (group prices, currency), so a shared cache would need a key per kind of shopper. The listing's
  counting was the heavier part and is shared now; this waits for a measured need.
- **Daily rollups for the reports.** The facts tables are one row per order, aggregated per request, and the group
  queries run on indexed day columns. Rollups pay off at tens of millions of facts, not before.
- **Read replicas for the shop.** Design 54: every shop query goes to the tenant's primary, by design.

## Proof

Unit: the order's words follow its writes (through the existing order tests), the full-text parser in its new place,
the settings' range. Integration: the orders list finds an order by the street, the postcode, a line's name and an
add-on's number through the new column (`OrdersAndCustomersRestTest`); the per-person lookups and the reviews'
purchase check (`PersonalData`, `Review`, `Exemption`, `Account`, `Customer` tests); the catalog search contract
with the new indexes and the shared results (`CatalogSearchTest`, `ListingBlocksTest`); the settings round trip
with `history_days`; the retention task over three tables with each item's current price staying
(`HistoryRetentionTest`). The migration ran on the development site and filled the words of its 165 orders.
