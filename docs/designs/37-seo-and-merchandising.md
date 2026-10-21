# 37 · SEO and merchandising

Status: built (2026-10-21), PLAN item 94.

## Decided (2026-10-21)

1. Who may review is the shop's choice (Settings → Reviews): anyone, with buyers' reviews marked; only buyers;
   or no reviews. Visitors without an account review as buyers from the link in their order, never by typing
   an email.
2. Who may keep a wishlist is the shop's choice too (Settings → Storefront): everyone, a visitor's in their
   browser until they log in; customers with an account; or no one.

## Tags in search

A product's tag names are words its search finds (both engines), and the index keeps its tags as a filter:
`SearchQuery::$tagIds`, the shop page's `?tag=`, a Products block's `tag`. Tagging a product, or renaming or
deleting a tag, queues its products. An OpenSearch index made before gets the new field when it is prepared.

## Reviews (src/Reviews)

- WordPress comments of type `orillacart_review` on the product, with `orillacart_rating` (1 to 5) and
  `orillacart_verified` as comment meta, written through `wp_new_comment`: the site's moderation, disallowed
  words, flood and duplicate checks and spam plugins see them as any comment. Replies to them (the shop's, from
  the Comments screen) are ordinary comments and show under the review.
- A buyer paid for the product (`OrderRepository::boughtProducts`: paid, not cancelled or failed): by their
  account (orders of the account or its email), or through a receipt token. The receipt and the account's order
  page link each item of a paid order to its product's reviews with `orillacart_review=<token>`; that page is
  private (no-store, no-referrer), and the review goes out under the order's name, marked.
- Known people go out as their first name and the first letter of their last ("Ada L."); visitors give a name
  and email. Each person reviews a product once.
- The product page (ProductPanel, under the buying form): the average, how many gave each number of stars, ten
  reviews a page (`?review-page=`), the form, or why there is none: off, the product's discussion closed, log in
  (only buyers, or Settings → Discussion asks commenters to log in), only buyers, reviewed already. Product cards
  show the stars. `GET /products/{id}/reviews` and `POST /products/{id}/reviews` (ten an hour) are public.
- On products the theme's comments do not show (an empty comments template; no comments blocks), and its comment
  form takes no comment: only reviews, and replies from those who moderate comments.
- The Comments screen lists them by type ("Product reviews") with a Rating column.

## Linked products

- Upsells and cross-sells: post meta (`orillacart_upsells`, `orillacart_cross_sells`), at most twenty each, saved
  with the post from the editor's Linked products panel.
- The product page shows four of its upsells ("You may also like") and four related products ("Related products":
  in stock, of its categories, failing those its tags, by the shop's order, besides itself and its upsells); the
  cart, four cross-sells of what is in it ("You may also need"), whose forms post so the page shows the cart with
  them. Drafts never show. All are the shop's product cards (`storefront/_product_card.html.twig`).

## Search engines and sharing

- A product's head carries schema.org Product data: name, description, images, SKU, barcode (`orillacart_gtin`),
  part number (`orillacart_mpn`), brand, today's price and stock (an AggregateOffer for products with
  variations), the average stars and five latest reviews. Filter: `orillacart_product_structured_data`.
- Open Graph tags (title, description, picture, price, stock) unless an SEO plugin writes them (Yoast, Rank Math,
  AIOSEO, SEOPress, The SEO Framework, Slim SEO; filter `orillacart_seo_plugin_active`), or always, or never.
- WordPress's own sitemap: products say when they changed and list their images (`ImageSitemapRenderer`, Google's
  image extension, only for entries with images). SEO plugins that write their own sitemaps turn WordPress's off.

## Wishlist and the products viewed

- A customer's wishlist is user meta (`orillacart_wishlist`, at most a hundred, newest first;
  `/account/wishlist`); a visitor's is in their browser and joins the account's when they log in. The product
  page saves it; the account page shows it (its own section, or beside the login for visitors). The privacy
  export lists it.
- The products viewed lately (twelve, in the browser) show under each product and in the Recently viewed
  products block.
- Both take their cards from `GET /product-cards?ids=` (published products only), so the pages around them are
  the same for everyone and page caches keep them.

## Analytics

The storefront script fires `orillacart:view_item`, `orillacart:add_to_cart`, `orillacart:begin_checkout` and
`orillacart:purchase` on the document with GA4's data (currency, value, items); a purchase fires once per
browser. With the setting on, they go to `window.dataLayer` as GA4 ecommerce events. On the server,
`orillacart_product_viewed` (not for pages a page cache serves).

## Category, tag and brand pages

Their pages list their products with the Products block, which takes the page's term when it names none. Block
themes get templates for the three; classic themes the plugin's `templates/wordpress/product-archive.php`, unless
they have a taxonomy template of their own.
