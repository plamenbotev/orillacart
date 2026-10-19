# 21 · Storefront filtering and search

Status: built 2026-10-18, in three steps: 21a (index, queue, MySQL engine, shop on the index),
21b (filters the shop defines, facets, price and stock filters, Search block), 21c (OpenSearch, queue status).

Decisions:

- MySQL is the default engine, with facets; OpenSearch is optional, for big catalogs and typo tolerance.
- Only filters the shop defines get facets. A filter has a label and the attribute names it covers ("Size",
  "Размер"), because attributes and their options belong to one product or one attribute set, so the same
  "M" on two products is two unrelated options.
- Filtering is variant-accurate: options, price and stock must all hold for the same variant.
- The plugin requires MySQL 8.0 or MariaDB 10.6 (`FOR UPDATE SKIP LOCKED`); there is no single-worker fallback.

## What shoppers get

- A search box and filters on the shop page (the Products block), and a Search block for themes.
- Filters: category (with subcategories), brand, the shop's attribute filters (Size: M, L), price range, in stock
  only; the block can also be fixed to featured or on-sale products. Each value shows how many products it
  would leave; values within one filter are OR, filters are AND, and a filter's own counts ignore its own choice.
- Sorts: the shop's order, newest, name, price both ways, and best match when searching.
- The form is a plain GET form, so it works without JavaScript; the script swaps the list region over HTML and
  keeps the URL (`?q=lamp&category=lamps&f[size][]=m&price_min=10&price_max=50`), so results can be shared.

## Why an index

Filtering by options, today's price (sales start and end) and stock across stockrooms means joining many tables
per request and computing prices in SQL. The catalog changes rarely compared with how often shoppers browse, so
each product is flattened once into a search document and the storefront reads only that.

The index supplies ids, totals and facet counts only. The cards render through `ShopCatalog::offers()` as now,
so the price and stock a shopper sees are always live; a queue delay only affects for a few seconds whether a
product matches.

## The index

`SearchDocument` (Application DTO) is built by `DocumentBuilder` from the catalog's application services and
the product's WordPress content: title, text (excerpt and content without tags), SKUs, categories (with their
ancestors), brands, option values per variant, today's price per variant (before tax, as the cards show it;
none when the price is hidden; a guest's price: a customer group's own prices are not indexed, so the price
filter and sorts go by what a guest pays, while the cards show the shopper their own), stock per variant, featured, on sale, menu order, published date, and when the
price next changes (a sale starting or ending). A product with variations has one variant per variation; one
without has a single variant with its own id, and its options are values of the whole product.

The MySQL engine keeps it in four tables:

| table | rows |
|---|---|
| `search_documents` | one per indexed product: title, text, price_min/max, in_stock, featured, on_sale, menu_order, published_at; FULLTEXT on title and on title+text |
| `search_variants` | one per variant: price, in_stock |
| `search_values` | product, variant (null: the whole product), facet, value, label; index (facet, value, product_id). Facets: `category`, `brand`, `sku`, `attr:<attribute name>` |
| `index_jobs` | the queue (below) |

Text search splits the query into words. Words of three letters or more that are not InnoDB stopwords are
required in `MATCH … AGAINST` in boolean mode, as prefixes; shorter words must appear in the title; stopwords
are dropped (InnoDB returns nothing for a required stopword or a short word). A query that starts a SKU also
matches it. Best match weighs the title above the text.

Ports (Search\Application): `CatalogSearch::search(SearchQuery): SearchResult` and `SearchIndex::put/remove`.
`ShopSearch` turns what the storefront asks into a `SearchQuery` (category slugs, the shop's filters) and
labels the facets.

## Keeping it fresh: a queue in the database

`index_jobs (product_id PK, version, available_at, attempts, locked_until, last_error)` (since 28a, `search.index` jobs in the shared `jobs` queue)

- **Producers** upsert one row per product:
  `INSERT … ON DUPLICATE KEY UPDATE version = version + 1, available_at = LEAST(available_at, ?), attempts = 0`.
  Events: `ProductChanged`, `StockChanged` (variation and option stock map to their products),
  `CatalogItemsRemoved`, `AttributeSetChanged` (products using the set), `SettingsChanged` (hidden prices:
  every product); WordPress hooks: a product post saved, its status or terms changed, deleted; a category
  moved under another parent (its products).
- **Sale boundaries**: a built document that knows when the price next changes leaves its job due then.
- **Workers** claim a batch in a short transaction:
  `SELECT … WHERE available_at <= now AND (locked_until IS NULL OR locked_until < now) AND attempts < 5
  ORDER BY available_at LIMIT n FOR UPDATE SKIP LOCKED`, set `locked_until`, commit; then build outside it.
  Completing deletes the row `WHERE version = claimed` (or moves `available_at` to the next price change);
  when the version moved, a change arrived while building, so the row is only unlocked and built again.
  A failure counts an attempt and retries later; after five the job is parked, logged and shown in the admin
  until the product changes again.
- **Runners**: WP-Cron every minute; at the end of a request that queued jobs (after the response is sent
  where PHP-FPM allows), a small batch, so the admin's changes show at once; `wp orillacart index work`
  (`--loop` for a supervisor), `wp orillacart index rebuild` (queues every product), `wp orillacart index status`.

## OpenSearch (21c)

Behind the same ports: one document per product with its variants nested, each variant carrying its own
options and the whole product's ("attr:size␟m"), so one nested query asks for a variant with all the chosen
ones; facets are global aggregations with every other choice applied, option values counted per product with
reverse_nested whichever attribute name holds them. Words match title (weighted) and text with typos forgiven,
English stopwords dropped as InnoDB drops them, and SKUs by prefix. The database's tables are always written
too: they answer while OpenSearch's index is being built (the worker marks it ready once nothing is due), when
OpenSearch does not answer, and they give the facets their labels. Settings check OpenSearch (reach it, make
the index) before switching, and switching queues every product. One contract test suite
(tests/Integration/CatalogSearchContract.php) runs against both engines; the OpenSearch run needs
ORILLACART_OPENSEARCH.

## Not in scope

Search analytics, synonyms, and searching orders or customers in the admin (they keep their own queries).
