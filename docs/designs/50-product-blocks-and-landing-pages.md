# 50 · Product pages and lists made of blocks, and landing pages

Status: built (2026-10-27), PLAN items 196–202. The user asked whether the shop needs a page builder of its
own for landing pages and for the product page and the product list. Not a builder: WordPress has one (the block
editor and the site editor), which owners and themes know. The shop gives it the blocks it lacks.

## Before

- **The product page** has block templates (design 45), but the buying part comes in one piece after the
  description, through the content filter (ProductPanel): pictures, price, quantity tiers, stock, the form, the
  wishlist, the files, then upsells, related products, recently viewed and the reviews. A template can move the
  description, not the price or the reviews.
- **The product list** is the Products block, one piece: category links, search and sorting, filters, grid or
  list, pages. The card is fixed (picture, name, stars, price, the way to buy).
- **Landing pages** are pages built by hand from core blocks and the Products block.

## Rendered on the server

Every block here is dynamic: the editor's side is React, the page saves only the block's comment and its
settings, and PHP renders the HTML as WordPress builds the page (render_callback, the shop's Twig templates).
Visitors and search engines get the whole page in the first response; the storefront script only makes the forms
work without reloading. The product's schema.org data and Open Graph tags stay in the head (ProductHead), apart
from the blocks, so a template the owner changes cannot lose them. Breadcrumbs carry schema.org BreadcrumbList
microdata.

## Product blocks

Each takes the product from its own Product setting or from the block context (postId: its page, a Query Loop,
a card of the Products block, a Single product block), so a landing page can show one product anywhere. Without
either it shows nothing on the site; in the editor it shows the latest product, so the site editor's template
looks like a product page.

| Block | What it shows |
|---|---|
| Product title | the name, as a heading or a link (for pages that are not the product's) |
| Product gallery | the large picture and the thumbnails, the chosen variation's pictures |
| Product price | the price as the shop shows prices, the sale, the lowest price; the quantity tiers (setting) |
| Product rating | the stars and the number of reviews, linking to them |
| Add to cart | in the box, the variation's text, SKU and stock (settings), back in stock, the choices, personalisation, quantity and button, the wishlist (setting), the files you get, and the add-ons' slots |
| Product reviews | the reviews and the form to write one |
| Related products | the upsells or the related products (a setting), as a row of cards |
| Product image | for cards: the picture, linking to the product, with a "Sale" badge (setting) |
| Product button | for cards: what the shop's card offers to buy it (add to cart, choose options, read more) |
| Breadcrumbs | Home › Shop › category › … › product, on product and category pages |
| Single product | a container for a landing page: the product blocks inside show the product it names (`providesContext`) |

The script finds a product's parts by `data-orillacart-for="{id}"` across the page, not inside one section, so
the price and the gallery change with the chosen variation wherever the template puts them.

**The content filter steps aside**: when the page's block template holds the Add to cart block, the description
comes alone; otherwise (classic themes, a template the site editor saved before) the filter appends everything as
before. The viewed product (analytics, recently viewed) is marked by the Add to cart block of the page's own
product.

**The default templates** (Product, Product: wide image, Product: text only) are made of these blocks: breadcrumbs,
gallery beside the title, rating, price and Add to cart; the description; upsells, related, recently viewed and the
reviews. The site editor changes them as it changes any template.

## The product list

- **The card is a template**: the Products block holds a Product card block; whatever is in it (Product image,
  Post title as a link, Product price, Product rating, Product button, any block, add-ons' blocks) is each card,
  rendered with the product as its context and as the global post (core blocks such as Post title read that, as
  in WordPress's Query Loop). Without one the shop's card stays. The list layout (rows) stays the shop's own.
- **The parts around the grid are settings of the block**: category links, search and sorting, grid or list
  switch, result count ("Showing 13–24 of 40", new), pagination. They share one search result and refresh
  together, so they stay one block; the filters are already their own (Product filters).

## Landing pages

**Patterns** in an "OrillaCart" category: shop hero (cover, words, button, featured products); category showcase;
sale banner with products on sale; new arrivals; product spotlight (one product's gallery, title, price, Add to
cart); gift card (when the shop sells one: Settings → Gift cards); a whole launch page made of them.

**The assistant** (design 49) lists the patterns (`list_patterns`) and makes a landing page from them
(`create_landing_page`, a draft). Its page tools still reach only the shop's pages: a landing page it made is one
(meta `_orillacart_landing`), the site's other pages are not. Putting one live is `publish_page`, the `publishing`
risk.

## Add-ons' blocks

Extension API 16: `Storefront\ProductBlock` (`Registry::productBlock()`): an id, a title, a description and
`render(ShownProduct $product, array $settings)`. The shop registers it as `orillacart-addon/{id}`, gives it the
product (context or its Product setting) as a plain `ShownProduct` (id, name, url, sku, price, regular price,
for sale, in stock, rating), shows it in the editor with the shop's blocks (server preview), and lets it into the
product card. The owner places it like any block.

## How the blocks get their product

`BlockDefinition::render()` takes a `BlockContext` beside the attributes: the post id from WordPress's block
context, the blocks inside and their rendered HTML. `ProductForBlock` picks the product; `ProductParts` renders
each part once per request (offers and cards kept for the request; whether one is for show is checked each time).

## Not built

A specification table: the shop's attributes are choices to buy, not facts to list. Core post blocks (Post title,
Post excerpt) inside a Single product block: they read the page's own post, so the spotlight uses Product title.
Several product card designs to switch between: the site editor's own patterns and templates do that.
