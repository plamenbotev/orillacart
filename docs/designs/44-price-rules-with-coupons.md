# 44 · Price rules with coupons, and with each other

Status: built (2026-10-23), PLAN item 97.

## Before

Price rules competed: of the rules that hold for an item, the lowest price won, and rules never added up. Codes and
automatic discounts (both are coupons) then came off whatever price the rules gave, always; a coupon's only say was
"not on items on sale", which is about sale prices, not rules.

## Which side owns the choice

The price rule. A rule is the price the shop decided on for some customers or items ("trade price", "members"),
and whether a code may still undercut it is part of that decision. Kept on the rule, one switch covers every code
and automatic discount, the ones made later too; kept on each coupon, every new code would need remembering to
exclude every rule's prices. A coupon keeps "not on items on sale" for sale prices.

## What a rule says

- **Discount codes and automatic discounts still apply on top** (on by default, as before). Off: an item whose
  price this rule set is left out of every coupon: a code that applies to nothing else says it does not apply to
  the items in the cart, as for any item it leaves out.
- **Combine with other price rules** (off by default, as before). The rules that do not combine compete as before;
  then each rule that combines applies to the price they gave, in the order the rules were made, and only when it
  lowers it (a combining "price of its own" above the price leaves it). Tiers by quantity work as before, and the
  product page's table shows the combined prices.

A coupon can still come off those prices when it says so: **"Also on prices whose price rule keeps discounts
out"** (off by default), for a code that must always work, such as a staff or apology code. The rule decides for
every coupon; a coupon made to override it is the shop's deliberate exception.

A rule keeps coupons out only when it set the price: the winning rule, or a combining one that lowered it. A rule
that holds but did not price the item (a higher price lost, or its quantity not reached) changes nothing.

## Since the second audit (150)

- Buy X get Y gives off only items no coupon leaves out: excluded products and categories, sale items when it
  leaves them, and prices whose price rule keeps coupons off (unless it overrides them).
- Free shipping makes the cheapest way to ship free; a coupon that says "Free on every way to ship" makes express
  and abroad free too.
- An automatic discount's combine box is about other automatic discounts: codes always apply on top of it, and a
  code that does not combine is refused while it applies (decided 2026-10-18); the admin says so.
- Paying later offers the ways to pay whose fee is the order's: the order keeps the fee it was priced with.
