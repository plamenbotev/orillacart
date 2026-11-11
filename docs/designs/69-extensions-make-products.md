# 69 · Extensions make products

Status: built (2026-11-11). PLAN items 577–582. Extension API 26.

An extension's tests run on the shop's own data, so a test written on one shop named products of that shop and
failed on every other: the trade extension, exported from one site, failed 18 of its 30 tests on another. And
extensions had real uses for making products that they could not reach: a catalogue kept in step with a supplier
or an ERP, a product made for an accepted quote, attribute sets kept from a PIM.

## Decisions

**As the import makes them.** A product an add-on makes is one row of a products file (`ProductRows`): every value
is checked as a cell is, a refused call changes nothing, categories are made with their parents, stock goes into
the stockrooms the row names. Attributes and attribute sets are saved as the product editor saves them, the
commands checked by the same constraints the editor's requests are. One way in, so an extension cannot make a
product the shop could not.

**A draft until published, and publishing its own permission.** `products: write` makes and changes drafts and
changes products; what shoppers see changes only with `products: publish` (publish, unpublish, trash), which a
manager grants on purpose, as `orders: create`.

**Names keep ids.** Attributes and options are given by name; saved over ones named alike they keep their ids, so
variations and placed orders that name an option stay right. A variation is added by option names.

**Texts cleaned whoever runs it.** Descriptions are written as by nobody, so WordPress cleans them as for an author
without unfiltered HTML, even when an administrator's request runs the extension.

**Tests leave nothing.** In a test the functions work as they do, inside the test's rolled-back transaction, and are
listed in its `effects`. Around each test the object cache adds nothing new, and afterwards the groups a test can
write to are let go (`RolledBackCaches`): this request's copies, and a persistent cache's group by group, or whole
where the cache cannot flush a group. A test that names a product the shop has not now fails alone, with which
(PLAN 575–576).

**Found by SKU.** `products()->search()` finds by SKU, words or category, drafts too, so a test or a sync finds what
it made last time instead of naming ids.

## On the way

The import refused a new product whose row gave a stock level: saved as counted, the product starts at 0 in the
active stockroom, and the level read before the save no longer matched. The level is now read after the save.

## Left out

Deleting products for good (the trash is enough, and placed orders keep them); images from extensions (the media
library's upload is the assistant's `upload_image`); bundles and downloads, which the product editor makes.
