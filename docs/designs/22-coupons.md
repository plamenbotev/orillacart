# 22 · Coupon codes

Status: proposed, waiting for approval.

## What the shop sets up

A **Coupons** screen (Shop → Coupons, DataViews list) where each coupon has:

- **Code** (unique, not case-sensitive) and an optional description for the shop.
- **Discount**: a percentage, a fixed amount off the order, a fixed amount off each matching item, or free
  shipping. A percentage may have a cap ("15 %, at most 20 €").
- **What it applies to**: the whole order, or only some products, variations, categories or brands; with
  exclusions (not on sale items, not these products or categories).
- **Rules**: starts / ends (date and time), minimum and maximum order subtotal, minimum quantity of matching
  items, the countries it is valid for, whether it combines with other coupons.
- **Limits**: total uses, uses per customer (by account, or by email for guests), first order only.

## How it works at checkout

- The cart and checkout get a "Coupon code" field. Applying a code checks it and explains a refusal plainly
  ("This code needs an order of at least 50 €.").
- `Coupons` (new area) owns the coupons and their rules. Checkout asks it, through its application service, for
  the discounts that apply to the priced lines; the discount is then a line in the `Bill`:
  - an order discount is spread over the lines in proportion to their totals, so each line's **tax is computed
    on the discounted amount** (which is what tax law expects in the EU and most US states);
  - free shipping sets the chosen rate to zero.
- Placing the order **records the redemption** in the same transaction as the order, under a row lock on the
  coupon, so usage limits hold under concurrent checkouts. Cancelling or failing the order frees the use.
- Orders keep the code, the description and the amount of each discount, so later changes to the coupon never
  change a placed order. The admin order screen, the receipt and the emails show them.
- Editing an on-hold order keeps its coupons and prices them again.

## Data

`coupons` (code, kind, amount, cap, rules as columns, active), `coupon_targets` (coupon, kind: product /
variation / category / brand, id, excluded), `coupon_redemptions` (coupon, order, customer or email, amount,
at). Order lines gain `discount`; orders gain `discounts` (JSON: code, description, amount).

## Answers (2026-10-18)

1. Several coupons per order, when each is marked "combines with others"; a coupon without the mark is used alone.
2. Coupons stay code-only; automatic discounts belong to item 23's dynamic prices.

## Questions for you

1. Should several coupons be allowed on one order (each marked "combines with others"), or one per order?
2. Do you want automatic discounts without a code (the same rules, applied when they match), or are those part
   of item 23's dynamic prices?
