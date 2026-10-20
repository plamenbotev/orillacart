# 35 · Fulfilment

Status: built (2026-10-20), PLAN item 92.

## Decided (2026-10-20)

1. Returns: the customer asks from the receipt, picking items, quantities and a reason, within the shop's
   return window; the shop approves or refuses, marks the parcel received, and refunds with the refunds the shop
   has (the items go back in stock).
2. Delivery day: a carrier may let the customer choose the day and a time slot: the weekdays it delivers, the
   lead days, a cut-off hour, how many days ahead, closed dates, and slots per day with an optional limit of
   orders each.
3. Pickup: a carrier may be the shop's own pickup, listing its places, each with an address, opening hours and a
   fee; the customer picks one at checkout, and the shop tells them when the order is ready to collect.
4. Addresses: the shop checks postcode formats per country and refuses wrong ones; an add-on may check the
   address and suggest a corrected one, which the customer takes or keeps theirs.

## Rates by item count, and free shipping

- A rate takes a range of items (`items_min`, `items_max`), each unit counted: a table of rates by item count is
  a rate for each range.
- `ShippingQuotes::freeFrom` finds the least order value at which one of the shop's own free rates would take
  the parcel there; the cart and checkout say "Add 12.00 more to your order for free shipping" while none does.
  Couriers' quotes are not looked at.

## Fees

- A payment method may cost a fee: a fixed amount and a percentage of the items (less discounts) and shipping,
  as the shop's prices are, named in the totals as the shop says (Payments → the method → Fee). It is taxed at the
  default tax group. An order with nothing to pay pays no fee for paying.
- Add-ons charge fees through `OrderFee` (extension API version 5): `fee(FeeQuery)` returns a `Fee` (label,
  amount, taxable, tax group). One that throws, or answers what is not a fee, is left out and the order notes it;
  one that fails closed refuses the order.
- `OrderFees` gathers them, `OrderPricing` adds them to the `Bill` (taxed per rate like shipping), and the order
  keeps them (`fees`, `fees_total`, `fees_tax`). Checkout prices the way to pay chosen, or the first offered, and
  shows each method's fee next to it; manual orders and editing an order on hold price them too.
- They show on the receipt, in the emails and the invoice, on the order screen, in the orders export (`fees`)
  and in `OrderSummary::$fees`. PayPal gets them as `handling`. A refund of the whole order gives back their tax.

## Shipments

- A shipment (`consignments`) carries some of the order's items (`items`: line positions and quantities), in
  parcels each with its own number (`parcels`). The shop picks what goes from what is left to send: each line's
  items, less those refunded and those in a shipment that still carries them (not cancelled, not returned).
- Booked with the carrier's courier through its shipping method (`CreatesShipments`), whose `BookedShipment`
  may give each parcel's number (version 5); cash on delivery is collected with the first shipment only.
- Or sent by the shop itself (a courier it has no add-on for): who carries it and each parcel's tracking number
  and link; it is on its way at once, and the shop says when it arrived or came back. Couriers are never asked
  about it.
- The order ships once nothing is left to send (the courier's first scan, or the shop sending it) and completes
  once every shipment arrived. The customer hears of each shipment (the follow-your-order email); the receipt and
  the emails show what each carries and its parcels.

## Pickup

- A carrier is the shop's own pickup when it has places (`pickup_locations`: id, name, address, hours, fee); it
  then has no rates and no method, and its tax settings tax the fees.
- Checkout offers each place as a way to ship (`pickup:7:sofia`), with its address and hours; the order keeps the
  place, with its hours, as its pickup point.
- On the order screen the shop tells the customer the order is ready there (the ready to collect email) and says
  when it was collected, which completes it.

## Delivery days

- A carrier's `delivery_schedule`: weekdays, lead days, cut-off, days to choose among, closed dates, time slots
  (from, to, orders a day), and whether the customer must choose. `DeliveryDays` works out the days in the shop's
  calendar and leaves out, or marks booked, the slots that took their orders that day (orders not cancelled,
  failed or refunded count, by `delivery_key` and `delivery_date`).
- Checkout shows the days of the chosen way to ship and the slots of the day chosen; placing the order checks the
  choice again. The order keeps the day and slot; the receipt, the emails and the order screen show them.

## Addresses

- `PostcodeFormat` knows about sixty countries' postcodes: an order's address with one that does not fit is
  refused, naming an example; postcodes are kept in capitals with single spaces.
- Add-ons check addresses through `AddressCheck` (version 5): `corrected(...)` or `notFound(...)`. Before placing
  the order checkout asks (`POST /checkout/addresses`, thirty an hour), shows what the first check with something
  to say said, and the customer takes the corrected address or keeps theirs. A check never refuses an order; an
  order placed with an address a check would have changed notes it. A check that fails is logged and passed over.

## Returns

- The shop's return window (`return_days`, Settings; none by default) counts from when the order first left
  (`fulfilled_at`: shipped, or completed without shipping first).
- Within it, the receipt offers to send back what shipped and was not refunded or asked for already, with a
  reason and a comment (`POST /receipts/{receipt}/returns`, ten an hour). The order needs attention, so the shop
  hears.
- `returns`: requested, then approved (with how to send the items) or refused (with why), the customer reading
  the answer as a note; received; refunded, through the provider or outside the shop, for what the customer paid
  for those items with their tax, putting them back in stock.

## Checkout choices

Checkout keeps the customer's choice of the way to ship and to pay, and of the delivery day and time, while a
refresh of the summary is on its way, and puts them back after it; it tells the shop the contact one change at a
time.
