# 41 · Finding products and orders in the admin

Status: building (2026-10-23), PLAN items 107 (products) and 108 (orders).

The shop's people look things up all day: a product to correct, the order a customer calls about. Both lists find
them by what the person has at hand (a SKU, a tracking number, an invoice number, a postcode) and narrow by the
facts of the shop (out of stock, on sale, unpaid, not shipped). Every filter is kept in the address, so a reload,
the back button or a link sent to a colleague shows the same list.

## Products (Products → All products, WordPress's list)

- **Search** matches the name and the description as WordPress does, and also a product's SKU or any of its
  variations' SKUs (part of one), and a product's number.
- **Filters**, beside WordPress's own (status, month):
  - category, brand and tag (subcategories included);
  - **kind**: ships, does not ship, with variations, a bundle, with download files;
  - **stock**: in stock, low stock, partly out of stock (some variations), out of stock, owed (sold below zero, backorders), always
    available (not counted), not for sale; and **in stockroom**, which counts only that stockroom's stock;
  - **show**: featured, on sale now, price hidden, not purchasable;
  - **price** from and to: what the shop charges now (the sale price during a sale), the product's or any
    variation's;
  - shipping class (or none), attribute set;
  - **missing**: SKU, picture, category, price (zero), weight (ships but has none) — what still needs doing.
- Stock is counted in active stockrooms, as the shop sells it. Low stock is the Stock page's: the item's own
  threshold, else the stockroom's, else the shop's. A product with variations is in stock when one of them is.
- A bundle has no stock of its own, so its items decide (the user flagged leaving bundles out): it is in stock
  while each required item can be sold as many times as the bundle holds (any one variation of a choice), out
  of stock when one cannot, "partly out of stock" when a choice's variation or an optional item is out, low or owed when
  one of its items is. A bundle priced by its items is never "missing a price".
- `Catalog\Application\ProductFinder` turns the filters into product ids; WordPress lists those. The list never
  reads more than ids, so the filters work the same on any theme or screen option.

## Orders (Shop → Orders)

- **Search** matches, besides the number, email, names and phone it matched: the shipping names, the street,
  city and postcode, the VAT number, the payment's transaction id, a line's name or SKU, and — through the areas
  that keep them — a shipment's or parcel's number, an invoice or credit note number, and a coupon code.
  `Ordering\Application\OrderLookup` is what an area implements to find orders by its own text (tagged services);
  Shipping, Invoicing and Coupons do.
- **Filters**:
  - status, now one or several;
  - placed, total, payment method, customer or guest, needs attention (as before);
  - **paid**: not paid, paid, partly refunded, refunded;
  - **shipped**: not shipped, shipped (orders that ship);
  - **shipping**: the way it ships (the methods orders have used);
  - **country**: billing or shipping country (the countries orders have);
  - **delivery day**: the day chosen at checkout, from and to;
  - **has**: a coupon, a gift card, backordered items, personalised items, a return, a customer's note,
    downloads;
  - currency, when the shop has orders in more than one.
- The choices offered (methods, countries, currencies) come from the orders themselves, so the lists stay short.

## Not in this round

Saved views ("my unpaid UK orders"), and searching inside answers to the shop's forms.
