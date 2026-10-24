# 33 · Stock history, back in stock, unpaid orders

Status: built (2026-10-20), PLAN item 90. Low stock thresholds and their email came with item 88
(docs/designs/31-emails.md).

## Stock movements

- `stock_movements` keeps every move of an item's level in a stockroom: by how much (`delta`), the level it left
  (`level_after`, below zero by what is owed), why (`StockReason`: sale, release, return, count, import,
  bulk_edit), the order it was for, the user who made it, when, and the stockroom's name, for when it goes.
- `StockService` writes them: setting levels (the product screen, the stock sheet, an import, a bulk edit) with a
  `StockNote` saying why and who; giving stock back, and taking it again, with the order. Stock taken while an
  order is priced is recorded by `OrderService` once the order has its number (`StockService::record()`), in the
  same transaction.
- Stock → History (`StockHistory`, `/stock-history`) lists the moves newest first, by product (its variations
  too), stockroom, reason and time, named as the stock screens name items, with who made each; and what moved
  in and out in all, by stockroom and reason, for the time looked at.

## Counting from nothing (129)

An item no stockroom has a level of is not limited by stock, which suits a shop that counts nothing. In a shop
that counts, that left items selling without limit by mistake: a new variation (its stock fields show only once
it is saved), a copied product (copied without stock), the items of a deleted stockroom. Now:

- A product or variation saved as "count stock" that no stockroom holds starts at 0 in the active stockroom that
  delivers fastest (`StockService::countFromNothing()`, on `ProductChanged`); a shop with no active stockroom is
  left as it is. Bundles take their items' stock.
- A stockroom that is the only one holding some items is not deleted (`orillacart_stockroom_in_use`): turning it
  off makes them out of stock instead, or they are counted in another stockroom first.
- The product list's stock filters count an item no stockroom holds as for sale, as the shop sells it.

## Back in stock

- A product that is for sale but has none in stock (`soldOut` on the offer and on each variation) offers "Email
  me when it is back": the visitor's email (the logged-in customer's filled in), guarded by the rate limit
  (`back_in_stock`, 10 an hour) and the honeypot. Asking twice keeps one request; what is not sold out is refused.
- When a stock change brings the item back (`StockChanged` from none to some), a job in the mail lane
  (`mail.back_in_stock`) writes to each who asked, once, in the language they asked in, fifty at a time, and
  forgets them. The email is `back_in_stock`, which the shop can turn off and edit.
- Requests are forgotten after half a year (daily), and with the person's data on a privacy request
  (`PersonErased`).

## Unpaid orders

A shop setting (`unpaidHoldDays`, empty by default: never) cancels, hourly, the orders on hold for a payment
made outside the shop that stayed unpaid that many days since they were placed: bank transfer and cheque, not
cash on delivery, which is paid when the order arrives. Their stock sells again and the customer gets the
cancelled email. An order paid or moved on since it was found is left.
