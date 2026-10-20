# 34 · Abandoned carts

Status: built (2026-10-20), PLAN item 91.

## Decided (2026-10-20)

1. A visitor is reminded only if they tick the box at checkout ("Remind me once about my cart if I leave without
   ordering"), which starts unticked. A logged-in customer finds it ticked and can untick it; turning it off for
   good in the account comes with the account page (item 93).
2. One reminder, the shop's hours after the cart was left (4 unless the shop says otherwise, on the Emails screen).
3. The reminder may offer one of the shop's coupons, chosen on the Emails screen: the email shows its code, and the
   link puts it in the cart.

## At checkout

The checkout page tells the shop (`POST /cart/checkout-contact`, the browser's own cart only) when it opens and
whenever the email or the box changes: the cart was at checkout then, with this email and this answer, in this
language. Without the tick the email is not kept on the cart.

## Abandoned

- Every hour (`RemindOfCarts`), a cart that was at checkout, holds something, and that nobody changed and nobody
  brought back to checkout for the shop's hours counts abandoned, once (`AbandonedCarts::mark`). What it held
  (items, subtotal, currency), its customer and, if they asked, their email are kept in `abandoned_carts` for the
  report. The cart remembers the abandonment until an order is placed from it.
- Those who asked get a job in the mail lane (`mail.cart_reminder`): the `cart_reminder` email (which the shop can
  turn off and edit) lists the cart as it is now, with a button back to it. A visitor's link carries a new token
  (`orillacart_cart_link`, only its hash is kept) that brings the cart to whichever browser opens it, under a new
  cookie; a customer's leads through the login. A coupon the shop can still offer (on, begun, not ended or used
  up) is shown, and the link puts its code in the cart (`orillacart_coupon`). Nothing is sent for a cart ordered,
  emptied or gone since, or whose customer unticked the box.
- An order placed from the cart counts it recovered, with the order and its total; the cart starts afresh.

## Report

Reports → Carts: for the days chosen, the carts left and what they held, how many were reminded, how many were
ordered after all and for how much, the recovery rate, against the days compared with; and the latest carts left,
with their state. They download as CSV like the other reports.

## Personal data

The email goes with the cart: after a month (`ForgetAbandonedCarts`, daily) the report keeps the numbers without
it. A privacy request that erases an email (`PersonErased`) erases it from carts and from what was left.
