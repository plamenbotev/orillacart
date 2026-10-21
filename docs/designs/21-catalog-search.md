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
- Filters by category (item 99): a filter shows only where the products in view have values for it. A
  category offers what the category above it does (the shop's filters, in the shop's order, at the top), or
  chooses its own on its edit screen ("Filters here": only those ticked, in their order; none is a choice too),
  which its subcategories then offer unless they choose again (`search_category_filters`,
  `ShopSearch::filters()`). A filter whose products in view have a single value is left out unless the shopper
  chose it (Settings → Storefront, on by default). The Product filters block on a category's or tag's own page
  counts and sends its choices within that one.
- Categories (item 100): the Product categories block shows categories as tiles (picture, name, product count
  with those below), the top ones, those below one, or those chosen; on a category's page, those below it, and a
  category's page template puts it above the products. The shop's links mark the top category holding the one
  chosen and add a row with those below it (or beside it).
- Grid or list (item 101): the Products block's own, a category's ("Shown as", which those below it take), or
  the shopper's (`?view=list`, kept by the forms). Rows add a short description.
- Addresses (item 103): a category's, tag's or brand's products are at its own page, laid out as the shop page
  (its blocks from the first to the last holding the Products or Product filters block, not its own words;
  else the Products block); the shop's category links go there, and a listing page asked for
  `?category=` or `?tag=` sends there for good (`ListingAddresses`). Pages are in the path
  (`/product-category/lamps/page/2/`, `/shop/2/`; `?product-page=2` without pretty permalinks, and sent on
  with them), never a 404 for WordPress's own list of posts. Listings narrowed by words, filters, sorting or
  view, and the cart, checkout and account pages, are `noindex, follow`.
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

The MySQL engine keeps it in three tables:

| table | rows |
|---|---|
| `search_documents` | one per indexed product: title, text, price_min/max, in_stock, featured, on_sale, menu_order, published_at; FULLTEXT on title and on title+text |
| `search_variants` | one per variant: price, in_stock |
| `search_values` | product, variant (null: the whole product), facet, value, label; index (facet, value, product_id). Facets: `category`, `brand`, `sku`, `attr:<attribute name>` |

Text search splits the query into words. Words of three letters or more that are not InnoDB stopwords are
required in `MATCH … AGAINST` in boolean mode, as prefixes; shorter words must appear in the title; stopwords
are dropped (InnoDB returns nothing for a required stopword or a short word). A query that starts a SKU also
matches it. Best match weighs the title above the text.

Ports (Search\Application): `CatalogSearch::search(SearchQuery): SearchResult` and `SearchIndex::put/remove`.
`ShopSearch` turns what the storefront asks into a `SearchQuery` (category slugs, the shop's filters) and
labels the facets.

## Keeping it fresh: jobs

Built first on a queue of its own (`index_jobs`); since 28a each product is a `search.index` job in the shared
`jobs` queue (28-background-jobs.md), with the product id as its unique key, in the `search` lane.

- **Producers** push one job per product; pushing a product already waiting only counts up its version, so a
  change made while it builds builds it again. Events: `ProductChanged` (also for the products of an attribute
  set that changed), `StockChanged` (variation and option stock map to their products), `CatalogItemsRemoved`,
  `PriceRulesChanged` (the products and groups a rule touches; every product only for a shop-wide rule),
  `SettingsChanged` (hidden prices: every product); WordPress hooks: a product post saved, its status or terms
  changed, deleted; a category renamed or moved under another parent, or deleted (its products).
- **Sale boundaries**: a built document that knows when the price next changes reschedules its job for then.
- **Workers**: the shared job runners claim a batch (`FOR UPDATE SKIP LOCKED`), build outside the transaction
  and complete it. A failure retries later; after five attempts the job is failed and shown on the Background
  jobs screen until the shop retries it, or the product changes again.
- **Runners**: WP-Cron every minute; at the end of a request that queued jobs (after the response is sent
  where the server allows), a small batch, so the admin's changes show at once; `wp orillacart jobs work
  --queue=search` (`--loop` for a supervisor); `wp orillacart index rebuild` (queues every product) and
  `wp orillacart index status`.

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
