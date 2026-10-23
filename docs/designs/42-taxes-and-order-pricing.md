# 42 · US sales tax, tax services, and how an order holds its prices

Status: built (2026-10-23), PLAN item 110. The user left the decisions to me; they are at the end, with what
was built differently from the proposal.

The user asked:
- whether the tax model supports US taxation;
- for an extension point so a tax service can do the taxes;
- for an API that changes prices on the order or on an item;
- whether orders should hold their prices as separate rows ("price items", "price line items").

The aim is to cover as many cases as possible while staying compliant.

## Where the shop is today

- **Rates.** A tax group (Standard, Books…) holds rates. A rate covers a country, optionally a state, and
  optionally postcode patterns, with a priority, and it may be compound. Several rates add up: a whole-country
  rate plus a state rate.
- **Who pays which rates.** One shop-wide basis decides: the shipping address, the billing address, the shop's
  address, or the EU origin/destination rules. Products and shipping rates name their tax group. VAT numbers are
  checked against VIES for EU reverse charge.
- **Rounding and prices.** Tax is rounded per line or once per order (a setting). Prices are entered with or
  without tax.
- **What an order keeps:**
  - each line's unit price, total, discount and tax, as one amount per line;
  - the order's taxes as a JSON list of name, percent and amount, summed across the order;
  - the shipping tax, fees with their tax, and discounts (code, description, amount);
  - refunds per line and amount.
- **Changing an order's prices.** Only an order on hold can be edited: unit price, quantity, items, shipping,
  coupons. Add-ons can change cart prices (PriceAdjuster) and add fees (OrderFee). They cannot change a placed
  order's prices.

## Does it support US sales tax?

**Partly: enough for a shop with nexus in one or two states and simple products. Not enough for compliant
multi-state selling.**

What works:
- **Nexus:** the shop collects only where it has rates.
- **State rates:** one per state.
- **Local rates by ZIP pattern:** a postcode-matched rate can approximate a city or county.
- **Exclusive prices:** prices without tax, as US shops show them.
- **Product taxability:** a "Clothing" group can have 0 % in Pennsylvania and New Jersey.
- **Shipping taxability:** a shipping tax group with 0 % in states that don't tax shipping.
- **Discount before tax:** tax is computed after the discount.
- **History:** a snapshot on each order, so later rate changes don't alter it.

What is missing:
1. **Jurisdictions below the state.**
   - About 13,000 US jurisdictions (county, city, special district) don't follow ZIP codes. A ZIP can span
     several.
   - Hand-kept tables can't be correct. In practice compliant shops use a tax service (Avalara, TaxJar, Stripe
     Tax, Vertex) that rates the street address.
2. **Origin or destination per state.**
   - Some states tax intrastate sales at the seller's location (Texas, Arizona, Illinois partly…) and
     interstate sales at the buyer's.
   - The shop's basis is one setting for everything.
3. **Exempt customers.**
   - Resellers, nonprofits and governments buy tax-free with an exemption certificate (number, states, expiry,
     reason).
   - There is no exemption on customers or orders, and no record of why an order was not taxed. Auditors ask
     for that.
4. **Tax per jurisdiction on the order.**
   - Filing a return needs the taxable amount and tax per state, county, city and district, and the exempt sales.
   - Orders keep one summed list of names and amounts with no jurisdiction code, no taxable base, and nothing per
     line.
   - Partial refunds then can't say which jurisdiction's tax they gave back.
5. **Tax on each charge.** Shipping, fees and gift wrapping are taxable or not by state. Today they carry one
   tax amount each, with no breakdown.
6. **Committing to a service.** A tax service needs three steps: quote at checkout, commit when paid, then
   adjust or void on refund or cancellation. Nothing carries those steps today.
7. **Smaller items.** Tax holidays (dated rate changes), and the marketplace-facilitator flag (not ours: we are
   the seller).

## Proposal

### A. Orders hold their prices as rows (the "price line items")

Recommendation: **yes**, in two tables. The order keeps a snapshot of how each amount came about, not just the
result. This is the model invoicing, tax services and auditors all expect, and it is how Stripe invoices and
Shopify orders hold prices.

1. **Order lines of every kind.** `order_lines` gets a kind: `item`, `shipping`, `fee`. Shipping and each fee
   become lines like items, with a quantity, a tax code or group, and their own taxes and refunds.
   - Today they are three special cases on the order: shipping total and tax, fees JSON, and item lines.
   - With one shape, tax, refunds, credit notes and exports each treat every charge once.
2. **`order_adjustments`: every change to a price, as a row.** Each row has:
   - the line it applies to, or none for the whole order;
   - its kind: `sale`, `price_rule`, `coupon`, `automatic_discount`, `manual` (the shop changed it),
     `add_on`, or `rounding`;
   - its source: the coupon code, the rule id, the add-on id, or the user;
   - the amount (negative lowers the price);
   - whether tax applies to it;
   - a reason, and when it was made and by whom.

   An order-level adjustment, such as €10 off the order, is spread over the lines it applies to. Each line keeps
   its share, so tax and partial refunds stay exact. A line's net price is its base price plus its adjustments.
   Today's per-line `discount` and the order's `discounts` JSON become views of these rows.
3. **`order_taxes`: tax per line, per jurisdiction.** Each row has:
   - the line;
   - the jurisdiction: country, state, county, city, district, with a code and level;
   - the rate, the taxable base and the amount;
   - whether it was exempt, and why;
   - who calculated it: the shop's rates, or a service with its transaction reference.

   The order's tax summary, invoices and reports sum these rows. A filing report by jurisdiction becomes a
   query.

Line base price × quantity + adjustments + taxes = the order's total. The figures stored on the order become sums
kept for listing, never the source of truth.

### B. A tax-service extension point (`TaxProvider`, extension API 9)

```php
interface TaxProvider {
    public function id(): string;
    /** Rates a cart or an order being edited: tax per line per jurisdiction, or ProviderUnavailable. */
    public function quote(TaxRequest $request): TaxResult;
    /** The order was paid: record the sale for filing. Returns the provider's transaction reference. */
    public function commit(TaxedOrder $order): string;
    /** A refund or credit note: report the tax given back, per line. */
    public function refund(TaxedOrder $order, TaxRefund $refund): void;
    /** A cancelled unpaid order: void the transaction. */
    public function void(TaxedOrder $order): void;
}
```

- **Request and result.** `TaxRequest` carries:
  - the ship-from and ship-to addresses;
  - every line (items, shipping, fees), each with its tax code (a product's group or a provider code
    mapped to it), quantity, net amount and adjustments;
  - the customer and their exemption;
  - the currency and the date.

  `TaxResult` gives `order_taxes`-shaped rows.
- **Choosing a provider.** The shop manager chooses one in Tax settings; the shop's own rates are the built-in
  provider behind the same interface.
- **Commit and refund calls** run as background jobs with retries, so checkout never waits on them.
- **When the service is down at checkout** (a shop policy, so a setting): use the shop's own rates and mark the
  order for review, or refuse the order. FailsClosed already models refusing.
- **Fewer calls.** Quotes are cached per cart content and address for a short time.
- **Address checks.** The address check API (`AddressCheck`, version 5) already lets a service correct
  addresses before rating them.

### C. Changing prices on a placed order (`Orders` and REST)

- **Before payment** (pending, on hold): the shop and add-ons can add, change or remove adjustments on the order
  or on a line. Taxes are then re-quoted and the total re-priced, as on-hold editing does today. Every change is
  an adjustment row with who and why, and a line in the order's history.
  - Extension: `Orders::adjust(number, OrderAdjustment)` and `Orders::removeAdjustment(number, id)`.
  - At checkout, `OrderAdjuster`: like `OrderFee`, but per line and in either direction, for loyalty, bulk or
    B2B prices. It joins `PriceAdjuster`, which sets a cart line's unit price.
  - REST: `POST/DELETE /orders/{id}/adjustments`.
- **After payment** the price can't change in place, because the invoice is issued.
  - Lowering it is a refund with a credit note, as today.
  - Raising it is a new charge: an adjustment in an "extra payment" document with its own invoice and a
    payment link (PayNow already exists).
  - Both are adjustment rows, so the order's history explains every amount.

### D. Exemptions

- **Customers:** an exemption with its reason (resale, nonprofit, government, other), certificate number,
  states or countries, expiry, and the certificate file (kept privately, like uploads).
- **Checkout:** a logged-in exempt customer gets exempt lines, and the order keeps the reason and the
  certificate on its `order_taxes` rows.
- **Shop manager:** can set an exemption on an on-hold order.
- **Tax service:** receives the exemption, since services check certificates by state.

## What it costs

A, B and C touch:
- the order model (lines, totals, refunds, invoices, credit notes, exports, reports, the extension `OrderSummary`);
- checkout and order pricing, and the admin order screen.

There are no users, so the old order columns go and nothing is migrated. Estimated as 4 PLAN items in order:
1. A, the model;
2. D, exemptions;
3. B, tax providers, with a provider SDK adapter left to an add-on;
4. C, adjustments API and UI.

A US filing report by jurisdiction comes with A.

## Decisions and what was built

1. **Model A.** Orders hold lines of every kind (item, shipping, fee), each with its `order_adjustments` and its
   `order_taxes` per jurisdiction (country, region, level, filing code, taxable, amount, refunded, exempt). The
   order's totals are worked out from them; the old aggregate columns are gone (3d62dd7, a04d612). The Taxes
   report sums them by jurisdiction and rate for returns (16668a2).
2. **Tax services:** only the extension point, `TaxProvider` in extension API 9 (bdecbb2). The shop's own rates
   stay the default, and the shop chooses what happens when the service fails: tax at the shop's rates with a
   note, or refuse checkout. No adapter for a particular service ships with the shop.
3. **Per-state sourcing:** built. Tax settings list the states that tax at the seller's address (62f30a9).
4. **Raising a paid order's price:** not for now. Only orders on hold change price; a paid order goes down by
   refunds and credit notes. An extra-payment document can come later, on the same adjustment rows.

Differences from the proposal:
- Prices change on orders **on hold**, as order editing already did, not on orders pending an online payment
  (the customer may be paying the old amount at that moment).
- The extension calls are `Registry::orderPrices()->adjust(...)` and the `OrderAdjuster` at checkout (691cb38).
  Taking a change off is for the shop manager (REST and the order screen); an add-on adds a change of the other
  sign.
- Exemptions are kept per customer (e0bd0ae, c390e62), with the certificate's number, not its file, and apply at
  checkout and when an order is edited. Setting one on a single order by hand is not built.
