# 39 · Commerce settings

Status: built (2026-10-21), PLAN item 96.

## Tax

- A tax rate may list postcodes: one (1000), a start (SW1*) or a range (1000–1999), the patterns shipping rates
  use (`PostcodePattern`, now in Shared). It applies only once the postcode is known and matches. Checkout, the
  order editor and manual orders pass the shipping and billing postcodes.
- A compound rate is charged on the price and the taxes listed before it (by priority). `Tax::parts()` works out
  each rate's part of an amount; with prices that include tax, an amount is shared out as the taxes on a price
  before tax would be, so a price including GST and a compound QST splits into the same parts. The rate's total
  shows as the effective percentage (5 % and a compound 9.975 % make 15.4738 %).
- Tax settings: "Round the tax of each line". Each line's tax is rounded and the rates bill the sum of those;
  otherwise, as before, each rate is rounded once over the order and shared out to the lines.

## Minimum order

- Settings: a minimum order, what the items come to after discounts, in the shop's currency. Below it, the cart
  and checkout say how much more is needed, and the order is not placed (`orillacart_below_minimum_order`).
  Orders the shop creates by hand are not held to it.
- A shipping rate's own minimum is its order value range, as before. When no rate ships the order to the address
  yet, checkout says "Delivery to this address needs an order of at least 50 €" instead of "We cannot ship your
  order to this address" (`ShippingQuotes::shipsFrom`, which shares its search with the free shipping hint).

## Buy X, get Y, and free gifts

Two kinds on the coupon engine, as codes or automatic discounts:

- **Buy X, get Y**: each round, the dearest matching items count as bought and the cheapest left as given, a
  percentage off (100 for free), up to an optional cap. When it names products or categories to give, buying the
  matching items gives those instead. A code says what is missing: "This code needs 3 of the items it applies to:
  2 to buy and 1 it gives", "Add the items this code gives off to your cart".
- **Free gift**: a product without variations or options, and how many. Once the rest of the order holds, checkout
  adds it and prices the order again (`OrderPricing::price` asks the discounts which gifts are missing), or makes
  the one already in the cart free. The gift does not count towards the order's size or the coupon's items. A gift
  not for sale in that quantity is left out. Checkout marks its line "Free gift"; the order records it as a line
  with a full discount, so editing the order finds it among the items rather than adding another.

## Gift cards and store credit (src/GiftCards)

- A gift card: a code of sixteen characters from letters and digits that cannot be mistaken for one another, in
  groups of four; only its SHA-256 is kept, and its last four characters. A balance, in the currency it was made
  in, every change of it an entry (`gift_card_entries`), who made it and why. It may expire and may be turned off.
- Store credit: the same balance tied to a customer's account, without a code. The customer screen shows it and
  its entries, and the shop adds to it or takes off it with a reason.
- Shop → Gift cards: make a card (worth, for whom, their email, a message, use by), its code shown once and sent
  by the "A gift card" email; the cards, with what each has left; a card's entries, a balance change, a new code
  for a lost one (the old one pays no more), turning it off. Settings: the products sold as gift cards, and how
  many months a card lasts.
- Sold: once an order is paid, each item of a product sold as a gift card becomes a card worth what was paid for
  it, sent to the buyer to give on; once per order.
- At checkout: codes entered (`POST /cart/gift-cards`, a rate guard of its own) and, for a logged-in customer with
  credit, "Pay with my store credit". Each takes what is left of the total in turn, store credit last. The
  summary shows each and "To pay"; an order they pay in full needs no payment.
- Placing: the order records each card's label and amount (`gift_cards`, `gift_card_total`) and what is left due
  (`amountDue`); the payment provider is asked only for that, without the item lines. The cards are taken in the
  order's transaction under a lock on each; a card spent elsewhere in the meantime stops the order. An order paid
  by a provider counts as paid what the provider took plus the cards.
- An order that is cancelled or fails gives each card back what it paid, once; one opened again, paid after
  all, takes it again as far as the cards have it, and notes any shortfall for the shop.
- Refunds: "As store credit, to spend in the shop", or for a guest "As a gift card, sent to …" (`MakeRefund`
  `storeCredit`, `RefundMethod::StoreCredit`).
- An order gift cards paid part of keeps its items: it is not edited, but cancelled and placed again.

## Other currencies

- Settings → Currencies: the currencies the shop takes besides its own, each with the shop's rate (units of it
  for one of the shop's, up to eight decimals) and how converted prices round: to the cent, to whole units, or
  just under the next unit (36.99). `orillacart_currencies`, in the settings file too.
- The Currency switcher block, or any link with `?orillacart_currency=USD`: the choice is a cookie for a year
  (`ShopperCurrency`); the shop's own admin screens price in the shop's currency. A page shown in another
  currency is sent private, so page caches do not keep it for others.
- Prices stay in the shop's currency. `Currencies` converts: `price()` for prices, rounded as set; `amount()`
  for other amounts (shipping, fees, coupon amounts, minimums) in the currency's decimals; `back()` the other
  way. The `money` filter without a currency shows a shop price in the shopper's currency; with one, an amount in
  that currency, in its own decimals.
- The cart and checkout price in the shopper's currency: unit prices converted and rounded, then the bill as
  always; shipping and fee amounts converted; coupon amounts and order size rules converted, percentages not;
  the minimum order converted. Shipping rates and fees still judge what the order is worth in the shop's
  currency. The order is placed, and paid, in the chosen currency; reports already count each currency apart.
- Gift cards pay only orders in their own currency.

## Not built

- Currency by the visitor's country, and rates fetched from a bank: the shop sets its rates.
- Postcode-level rates in the EU VAT table (`Add EU standard rates`) and a shipping tax that follows the items.
- Cash rounding of the total (to 0.05).
- A price filter in the shop listing in other currencies: it filters by the shop's prices.
- Structured data (JSON-LD) keeps the shop's currency, as search engines crawl without the cookie.
