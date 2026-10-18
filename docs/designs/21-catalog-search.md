# 21 · Storefront filtering and search

Status: proposed, waiting for approval.

## What shoppers get

- A **search box** and a **filter panel** on the shop page (the Products block), and a Search block for themes.
- Filters: category (with subcategories), brand, price range, attribute options (Size: M, L), in stock only,
  on sale, featured. Each shows how many products it would leave (facet counts).
- Sorts stay as they are (order, newest, name, price), plus "best match" when searching.
- Results reload the product list region over HTML, like the rest of the storefront, and the URL keeps the
  query (`?q=lamp&size=m&price=10-50`) so results can be shared and cached.

## Why an index

Filtering by options, price today (sales start and end) and stock across stockrooms means joining five tables
per request and computing prices in SQL. The shop's numbers change rarely compared with how often shoppers
browse, so each product is flattened once into a search document and the storefront reads only that.

## The index

One row per sellable product (variations folded into their product), in `orillacart_search_documents`:

| column | |
|---|---|
| product_id | key |
| title, sku, text | title, every SKU (product and variations), excerpt and content stripped of tags; FULLTEXT on title+sku+text |
| categories, brands, options | JSON arrays of term and option ids (and generated columns for MySQL multi-valued indexes where available) |
| price_min, price_max | today's lowest and highest price, sale included, before tax |
| sale_until | when the running sale ends, so the price is refreshed then |
| in_stock, featured, on_sale, menu_order, published_at | |

Behind one port, `CatalogSearch` (Application): `search(SearchQuery): SearchResult` with ids, total and facet
counts. Two adapters:

- **MySQL** (default, no setup): FULLTEXT `MATCH … AGAINST` in boolean mode for text, `JSON_OVERLAPS`/
  `MEMBER OF` for filters, `GROUP BY` queries for facet counts. Good to tens of thousands of products.
- **OpenSearch** (optional, a host and credentials in settings): the same documents in an index, with
  aggregations for facets, typo tolerance and stemming per language. Settings test the connection before
  switching, as downloads do.

Both read the same documents, built by one `SearchDocuments` builder from the catalog's application services.

## Keeping it fresh: a queue in the database

`orillacart_index_queue (id, product_id, reason, available_at, attempts, locked_until)`.

- **Producers** are the events the catalog already raises: `ProductChanged`, `StockChanged`, `CatalogItemsRemoved`,
  post status and term changes (WordPress hooks), settings that change prices (hidden prices, catalog only).
  Each inserts a row; a unique key on `product_id` where not yet taken keeps one pending row per product.
- **Sale boundaries**: when a document is built with `sale_until`, a row is queued with `available_at` then.
- **Workers** claim a batch with `SELECT … FOR UPDATE SKIP LOCKED` inside a short transaction, set
  `locked_until`, commit, build the documents, then delete the rows. A worker that dies leaves `locked_until`
  to expire and the rows are claimed again; `attempts` stops a product that keeps failing after five tries and
  logs it.
- **Runners**: WP-Cron every minute (always on), `wp orillacart index work` for a system cron or a supervisor
  (`--loop` to keep running), and a full rebuild with `wp orillacart index rebuild`. MySQL 8.0 and MariaDB
  10.6 have SKIP LOCKED; on older servers the claim falls back to `UPDATE … SET locked_until … LIMIT n` with a
  random token, which is safe with one worker.

The admin sees the queue length and the last run on the settings screen, with a "Rebuild the index" button.

## Not in scope

Search analytics, synonyms management, and searching orders or customers in the admin (they keep their own
queries).

## Questions for you

1. Is MySQL FULLTEXT the default, with OpenSearch as an option, the split you want, or should OpenSearch be
   the only engine and MySQL a plain fallback without facets?
2. Should attribute filters be offered for every attribute, or only for attributes the shop marks "filterable"?
