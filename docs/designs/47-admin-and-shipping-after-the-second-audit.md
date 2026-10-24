# 47 · The admin and shipping, after the second audit

Status: built (2026-10-24), PLAN items 154 and 155, and the polish after them.

## The admin

- **Refusals name their fields.** `errorMessage()` adds each refused field to the message, in the form's own
  words where the screen gives them (`FieldLabels`: "rates[]" → "Rate %d", "rates[].price" → "Price"), else the
  API's name in words. Screens that show refusals beside their fields (tax rates, search filters, settings) keep
  doing so.
- **Unsaved changes ask before they go.** `useUnsavedChanges()` keeps a page-wide list of forms with changes, which
  also guards leaving the page; `useLeaveGuard()` asks, in the admin's own dialog, before a screen closes a form or
  opens another row while one is listed. Saving closes without asking. Coupons, price rules and automatic
  discounts, tax rates, email templates, an order's items, contact and note, forms, attribute sets, customers,
  search filters, gift-card balances and webhooks use it. A form that stays on screen (issuing a gift card) is not
  listed: opening a row loses nothing of it.
- **What cannot be undone asks first:** deleting failed jobs, a tax rate, a variation, a download file, a shipping
  class, an automatic discount; every refund, through a provider, as store credit or made outside the shop.
- **Dates on the shop's clock** (`shopDateTime()`, `shopDate()`): moments are shown in the shop's time zone, as
  they are entered, in the browser's language.
- **Amounts as the storefront shows them:** the site language's separators, the shop's decimals for its currency
  and the currency's own (ISO 4217) for another, rounded exactly (`roundDecimal()`: 1.005 is 1.01).
- **Pickers reach everything:** the cart reminder's coupon is searched among all codes; the automatic discounts
  are all listed.

## Shipping

- **Booked, not collected** (`Tracking::BOOKED`, API 13): the order waits and the shop can still cancel the
  shipment. A courier's late "booked" never takes back one it collected.
- **Classes and their rates.** A rate carries the classes it lists, or every item. A carrier may keep goods of a
  class one of its rates lists to the rates that list it ("Goods of a class one of these rates is for go only by
  the rates for it", off by default): then "Bulky" goods go by the freight rate alone, not by the standard rate
  too. While it is off, the admin warns beside a rate that carries every item which classes it carries besides
  another rate.
- **Postcodes.** A range's two ends have as many digits as each other and compare that many of a postcode's
  leading digits, dashes left out: the Polish 10-200 is in 10000-10999, and 94103-1234 in 94000-94999. Two groups
  of different lengths are one postcode (10-200, 100-0001), not a range.

## Smaller things

- An update leaves the shop's pages as they are; activation makes again only one that is gone or in the trash.
- A cart reminder is taken before it is written, in one statement (`claimReminder()`), and let go when the mail
  system refuses it: two workers, or a retry after it went, send it once.
- **Earlier guest orders.** A new account takes the orders placed with its email as a guest before: its owner
  proved the address by following the link emailed to it. Customers → Accounts may turn that off; then an account
  takes only the order placed while asking for it, and the confirmation email promises no more.
- The cart shows checkout's lines whenever checkout priced it, so they add up to the estimated total; abandoned
  carts count a recovered order in another currency in the shop's.
