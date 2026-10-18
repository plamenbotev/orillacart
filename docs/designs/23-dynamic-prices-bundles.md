# 23 · Dynamic prices, bundles and sets

Status: building (2026-10-18): 23a price rules and customer groups are built; 23b automatic discounts and 23c
bundles and sets follow. Changes from the draft below:

- One kind of price rule covers groups, quantity tiers and dates: a rule has tiers (from 1 item, from 10…),
  optional groups, products, categories and brands, and optional dates. Where several rules hold, the lowest
  price wins, never below what a rule gives, so rules do not stack.
- Rules change the product's or variation's own price; option adjustments stay on top.
- Customer groups are the shop's own (Shop → Customers), not WordPress roles.
- The search index keeps the guest price; logged-in customers see their group's price on the product page, in
  listings, the cart and checkout.

## Dynamic prices

**Price rules** (Shop → Price rules) change the unit price before the cart and checkout see it:

- **Quantity tiers** on a product or variation: 1–9 at the price, 10–49 at 9 €, 50+ at 8 € (or −10 %, −20 %).
  The product page shows the table; the cart prices each line by its quantity.
- **Customer groups**: a group (Wholesale, VIP) is a WordPress role or a list of customers; a rule gives the
  group a percentage or a fixed price on chosen products, categories or the whole shop. Logged-in customers in
  the group see their price everywhere, including the shop listing.
- **Scheduled rules**: any rule may have start and end dates, like sales, for seasonal pricing.
- **Order of rules**: a product's sale, then the best matching group price, then quantity tiers on that price.
  Rules never compound by surprise: each rule says whether it applies on top of a sale.

Pricing moves from `Pricing::priceAt($now)` to a `PriceResolver` in the Catalog application layer that takes
the product, quantity, customer groups and time. `ProductQuotes` and `ShopCatalog` call it, so carts,
checkout, the storefront and the search index (item 21: `price_min` for the guest price) agree.

## Bundles (a product made of products)

A **bundle** is a product type whose items are other products or variations with quantities ("Camera kit:
1 camera, 2 batteries, 1 bag").

- **Price**: fixed, or the sum of the items with a discount (−10 %).
- **Stock**: a bundle has no stock of its own; it is available while every item is, and selling one takes each
  item's stock (one `StockService::take` for all, all or nothing, backorders as each item says).
- **Choices**: an item may be optional, or a choice among variations ("bag: black or brown"), chosen on the
  product page like options.
- **Orders** record the bundle line and its items as child lines, so packing lists, stock returns on
  cancellation and refunds (item 24) see what was in it.
- **Shipping** weighs the items, or the bundle's own weight when it is shipped as one package.

## Sets (kits sold as one item)

A **set** is the simple case: a fixed bundle without choices, shown and sold as one item, its stock taken
from its items. It is a bundle with every item required, so it needs no separate model; the admin offers
"Set" as a shortcut that hides the bundle options.

## Answers (2026-10-18)

1. Customer groups are a list the shop manages (Shop → Customers), not WordPress roles; a customer can be in
   several.
2. A bundle is one line in the cart and on the order, with its contents listed underneath; stock is still taken
   per item.
3. Automatic discounts without a code live here, not in coupons (answered with item 22).

## Questions for you

1. Customer groups: WordPress roles only, or a separate group list the shop manages?
2. Should bundle items show as separate lines in the cart (with the bundle as a heading), or as one line with
   the contents listed underneath?
