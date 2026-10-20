# 32 · Import and export

Status: built (2026-10-20), PLAN item 89.

## Decided (2026-10-20)

1. Columns are the shop's own, extendable by add-ons, and chosen by the shop manager in the admin: which to
   export, and in what order. The shop keeps the choice for the next export.
2. The products file is the shop's own format, not another shop system's.
3. The orders export downloads the list as filtered, a batch at a time, so a large shop does not run out of
   memory.
4. Bulk editing is bulk edit on the products list: prices, sales and stock.
5. A duplicate is a draft without stock.

## Columns

- Add-ons register `OrderColumn` (orders export) and `ProductColumn` (products file) on the registry, extension
  API version 4. A `ProductColumn` that implements `ImportsProductColumn` is read back by the import, after the
  shop saved the row. docs/extensions.md describes them.
- The shop's own columns are `Column`s with the same id, name and value; their names are translated
  (`ColumnNames`). An add-on's column with a shop column's id is logged and left out; one that throws gives an
  empty cell, and is logged.
- The export dialog (`ExportDialog`) lists every column, the chosen ones first in their order, the add-ons'
  marked; the shop manager ticks and moves them. `ColumnChoice` keeps the choice (`orillacart_export_columns`).

## Orders export

"Export" on the Orders screen downloads what the list finds, with its filters, as CSV (headed by the columns'
names) or JSON (objects keyed by column id). The file streams from `admin-post.php`
(`action=orillacart_export_orders`), checked with the REST nonce and the shop manager's capability, a hundred
orders at a time. CSV is UTF-8 with a byte-order mark, and a cell that would start a spreadsheet formula
(`=`, `+`, `-`, `@`) and is not a number gets an apostrophe first.

## Products file

Shop → Import and export (also beside "Add New" on the products list).

- **Export**: a row for each product and one for each of its variations after it, headed by the columns' ids:
  `id`, `variation_id`, name, type, status, SKU, price, sale (price, from, until), stock mode, backorders and
  their limit, low stock threshold, measures, taxable, purchasable, featured, categories (with their parents:
  "Lighting > Desk lamps"), brands, tags, the texts, options, and `stock_<stockroom id>` for each stockroom.
  Lists are separated by " | ". A product's row leaves the variations' columns empty, and a variation's an empty
  value where it follows its product.
- **Import**: the upload is read first (at most 50 MB and 50,000 rows), and the screen shows which columns it
  changes and which it leaves (unknown, or only exported, such as options). Started, a job imports fifty rows a
  run in the `imports` lane; the file is kept privately until it is done. A row finds its product by
  `variation_id`, `id` or SKU and changes only the file's columns: an empty cell clears what may be empty (a SKU,
  a sale, a measure, a stockroom's level) and keeps a price or a name. A row that finds nothing adds a product,
  which needs a name and a price. Variations are made on the product's screen; rows only change them. Categories
  missing are made, with their parents. A refused row is reported with its number and why (the first 200), and
  the others go on.

## Bulk edit and duplicate

- Bulk edit on the products list sets prices, or raises and lowers them by an amount or a percentage (in the
  shop's decimals); sets sales, takes a percentage off the price, or ends them; and sets or adds to the stock of
  one stockroom. A product's variations change with it, each from its own price and stock. A product that cannot
  take the change is left as it was and named on the list (`BulkEdits`).
- "Duplicate" under a product's name makes a draft "(copy)" with its texts, terms, picture and gallery, prices,
  measures, attributes (with options of its own) and variations, without SKUs, stock or downloads, and opens it.
