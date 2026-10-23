# 43 · Tax outside the US: how OrillaCart compares with WooCommerce, and the gaps closed

Status: built (2026-10-23), PLAN items 117–125; what was decided and built differently is at the end. The user asked where OrillaCart stands on tax compliance
worldwide and how it compares with WooCommerce, and then to close the gaps. None of the items calls a service
the shop does not call today (VIES for EU VAT numbers is the only one, and stays).

## Compared with WooCommerce 9.7.1

Read from WooCommerce 9.7.1's own code (includes/class-wc-tax.php, class-wc-cart-totals.php,
admin/settings/views/settings-tax.php, admin/importers/class-wc-tax-rate-importer.php, src/Admin/API/Reports).

| | WooCommerce core | OrillaCart before 117 |
|---|---|---|
| Rates | Tax classes; rates by country, state, postcode (ranges, wildcards), city; priority, compound, a "shipping" flag per rate | Tax groups; rates by country, state, postcode patterns; priority, compound. No city, no shipping flag |
| Rates in bulk | CSV import and export (country, state, postcode, city, rate, name, priority, compound, shipping, class) | EU standard rates in one click; no CSV |
| Address taxed | Shipping, billing or the shop's | The same, and the EU origin and destination (OSS) rules |
| Prices entered with tax, for a customer elsewhere | The price before tax stays, so the price paid changes with the rate; a filter (`woocommerce_adjust_non_base_location_prices`) keeps the price paid instead | The price paid stays and the tax inside it follows the customer's rate; no choice |
| Showing prices | With or without tax in the shop and, separately, in the cart and checkout; a suffix such as "incl. VAT" with {price_including_tax} and {price_excluding_tax}; taxes as one total or by rate | As entered; taxes by rate |
| Shipping's tax | A tax class of its own, or the cart items' (one class, or the highest rate) | A tax group of its own per way to ship |
| Rounding | Per line or at subtotal | Per line or per order |
| Tax-exempt customers | A flag on the customer, set only by code | Exemptions by reason, number, states and last day; certificates sent by customers and reviewed (115) |
| EU VAT numbers, reverse charge | None (a paid extension) | VIES check, reverse charge, Article 196 note |
| Invoices, credit notes | None (a plugin) | Numbered without gaps, the seller as when issued, PDFs on emails |
| Reports | Taxes by rate code: orders, total, order tax, shipping tax | Taxes by jurisdiction and rate: taxable, exempt, tax, refunded; US economic nexus (114) |
| Tax services | Its own WooCommerce Tax extension (a service); others replace the calculation each in their own way | One extension point (TaxProvider) with tax codes, fallback and a ten-minute cache (110, 113, 116) |
| Placed orders | Edited by hand and recalculated | Price changes as rows with a reason (110, 111) |

Where WooCommerce does more: how prices are shown, the CSV of rates, city rates, the per-rate shipping flag,
shipping taxed like the items, and the choice for prices with tax abroad. Neither does e-invoices, import
schemes for low-value goods, the EU's €10,000 threshold, tax numbers outside the EU, or VAT in the seller's
currency on invoices in another one.

## The items

### 117 · Showing prices with or without tax
Two settings, as WooCommerce has them: prices in the shop (product pages, lists, blocks) and in the cart and
checkout, each "with tax" or "without tax", whatever way they are entered; and a suffix after prices in the shop,
such as "incl. VAT", with `{price_including_tax}` and `{price_excluding_tax}`. Shown prices use the shopper's
address when it is known and the shop's otherwise (the rates of the address the taxes go by). Orders, receipts and
invoices keep both amounts, as they do.

### 118 · Prices with tax, for a customer elsewhere
A setting: "the same price everywhere" (today's behaviour, and the default: what B2C shops in the EU usually
want) or "the same price before tax" (WooCommerce's default: the shop's own tax comes off and the customer's goes
on).

### 119 · Shipping taxed like the items
A way to ship may name a tax group, as now, or follow the items. Following the items shares the shipping out by
the items' taxable amounts and taxes each share at its items' rates, as EU VAT treats shipping (it follows the
goods it carries). This is proportional, not WooCommerce's highest rate.

### 120 · Rates in bulk, cities, and rates that leave shipping alone
- A rate may list cities and may say it does not apply to shipping (some US states do not tax shipping).
- Shop → Tax imports and exports a group's rates as CSV in WooCommerce's columns, so a shop moving from it brings
  its rates. An import checks every row before it saves any, and replaces or adds as the shop chooses.

### 121 · The EU's €10,000 threshold
Shop → Tax shows, for a shop in the EU, its sales to consumers in other EU countries (no VAT number, reverse charge
not applied) this calendar year and the last, against €10,000 (a setting, in the shop's currency when it is not
the euro). It warns when the threshold is crossed while taxes still go by the shop's address.

### 122 · Import schemes for low-value goods
Registrations kept in Shop → Tax: the EU's IOSS, the UK, Norway's VOEC, Australia, New Zealand. Each has the
shop's number there and its limit (the goods' value before shipping and tax, per order or per item, in the shop's
currency, prefilled when the shop's currency is the scheme's):
- under the limit, the destination's rates are charged at checkout (as the shop has them), and invoices show the
  number;
- over it, or without a registration for a country that has one of these schemes, no tax is charged and checkout
  says import taxes and duties may be due on delivery.

### 123 · Tax numbers outside the EU
Checkout's VAT number field becomes a tax number field that business customers can fill in for any country.
The format is checked, and the checksum where the country has one: the UK's VAT, Switzerland's UID, Norway's MVA,
Australia's ABN, New Zealand's GST and Canada's BN. The number goes on the order and the invoice. Reverse charge
stays for the EU, checked with VIES.

### 124 · VAT in the seller's currency
An order keeps the exchange rate it was priced at. An invoice or credit note in another currency than the shop's
also shows its taxes, and the total tax, in the shop's currency at that rate (Article 230 of the VAT Directive).

### 125 · E-invoices
Each invoice and credit note can also be had as an EN 16931 XML file, in the format the shop chooses: UBL (Peppol
BIS Billing 3.0) or UN/CEFACT CII (XRechnung). The shop downloads it from the order; the customer's emails can
carry it next to the PDF. Checkout can ask business customers for their own reference (the buyer reference,
Germany's Leitweg-ID), and the order number stands in for it otherwise. Sending through Peppol needs an access
point, a service, so it is left to add-ons. The XML is offered through the extension API for them.

## Decisions and what was built

- **117, 118** (982f4af): the catalog's shown prices use the customer's saved address, or the shop's for visitors,
  as checkout's estimate does; a page cache therefore serves visitors the shop's prices, as WooCommerce's does.
  The cart and checkout convert each line, the shipping and the fees from the bill's own tax, so the totals and tax
  lines never move. Without a text after prices, prices with tax say "incl. tax" as before, and prices the shop
  chose to show without tax say "excl. tax". Price filters and sorting stay on the entered prices. Found on the
  way: an exempt customer with prices entered with tax paid the price with tax and no tax charged; they now pay it
  without the tax in it (Tax::asPaid()).
- **119** (d6683fa): a shop-wide setting, as WooCommerce's is, rather than one per way to ship; shares are by what
  the items cost before their discounts. With an import scheme in play, shipping follows the scheme instead.
- **120** (9ef91bd): a group holds up to 5,000 rates; more belongs with a tax service. WooCommerce's "tax class"
  column is not read: the file goes into the group chosen. Checkout sends the city for the totals now.
- **121** (3eaccbc): sales in the shop's other currencies count at today's exchange rate; orders reported before
  read as sales to consumers until the reports are rebuilt. "Passed" is more than the threshold, as the law says.
- **122** (6ed67e3): the decision is per order, for the physical goods and their shipping; services stay taxed as
  the settings say. The value compared is the goods' price before shipping and tax, before coupons, in the shop's
  currency. Limits are kept in the shop's currency, the law's prefilled when it is the scheme's.
- **123** (a50a75f): an invalid number outside the EU is left off the order (and the customer told), rather than
  refusing the order.
- **124** (1b9f880): as designed.
- **125** (9959a3c): the seller's postal address is read from the invoice address's lines (the first the street,
  the last the city) with the shop's postcode and country; a line taxed by several rates of one place goes with
  their sum. The files are built to EN 16931's rules; 126 runs them through the official validators. The buyer's reference is asked of business customers whenever the tax number
  is.
- **126**: the files go through the official validators, in a suite of their own that needs Java and never ships
  (`npm run test:einvoices`; bin/einvoice-validators fetches pinned versions of KoSIT's validator 1.6.3 with
  XRechnung 3.0.2's rules, OpenPeppol's BIS Billing 3.0.20 Schematron compiled with SchXslt, and checks their
  SHA-256). Eighteen documents, in both formats: two rates, the reverse charge, a certificate's exemption, an export,
  a seller not subject to VAT, another currency, credit notes, and a domestic business sale. Warnings fail too.
  What they found, and how it was fixed:
  - Peppol takes participant IDs, not emails: the seller's own Peppol ID (a setting) or its VAT number's under its
    country's scheme, and the buyer's VAT number's. A document is for Peppol only when both have one; a consumer's
    invoice is plain EN 16931 (`urn:cen.eu:en16931:2017`, email addresses), which Peppol would refuse anyway. An
    add-on is told the buyer's Peppol ID when the file is for Peppol, and finds the access point by it.
  - Lines not subject to VAT (O) carry no rate; the VAT breakdown keeps its 0, which XRechnung asks for.
  - The seller's postcode is read from the invoice address when the shop has none of its own.
  - XRechnung needs the seller's phone: Settings → Invoices has it (refused empty with CII), and an invoice email
    (the shop's when empty).
  Checking each document as it is issued is for an add-on (typical hosting has no Java), or the access point's.

