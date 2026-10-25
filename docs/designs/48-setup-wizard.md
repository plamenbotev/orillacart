# 48 · A setup that leaves the shop ready to sell, within the rules

Status: design, waiting for approval (2026-10-25), PLAN items 167–176. The user asked for a setup wizard that
sets up taxes, shipping and payments, after which the shop is fully working and follows the rules where it sells,
and is easy to use. The shop's MCP tools (design 49, later) reuse its plan-and-apply service.

## Before

Shop → Setup has five steps:
- **Your shop**: country, currency as a typed code, units.
- **Payments**: ticks for the offline methods; PayPal sends the owner to another screen.
- **Shipping**: one flat rate to the shop's own country.
- **Products**, then **Done**.

What it leaves unset:
- **Tax**: no groups and no rates, so the shop charges no tax.
- **Who the seller is**: invoice details are a separate screen, so invoices go out with no seller on them.
- **Where the shop sells**, and shipping anywhere except home.
- **Prices**: whether they include tax, and how they are shown.
- **Legal pages**, and a checkout that has no terms to accept.
- **Emails**: the sender is not checked.

"Done" calls the shop set up whatever is missing.

What the plugin already has, which the setup should switch on rather than build again:
- **Tax**: EU standard rates, the OSS origin and destination rules, reverse charge with a VIES check, the €10,000
  threshold, US nexus, tax services (TaxProvider), and prices shown with or without tax.
- **Invoices**: numbered with no gaps, credit notes, e-invoices (UBL/CII, Peppol id).
- **Other settings**: the lowest-price rule (Omnibus), return days, unpaid-hold days.
- **Site Health checks**.

## How it works

**The wizard asks about the business, not about settings.** The owner answers questions such as these:
- Do you sell to consumers, to businesses, or both?
- Are you registered for VAT? Are you in the OSS?
- Where do you ship?

A **country profile** turns the answers into settings. The wizard then shows the result in plain words before
saving anything: "Customers in Germany pay 19% VAT, included in your prices; businesses with a valid VAT number
pay none (reverse charge)." The owner can change any line.

**Plan, then apply.**
- `POST /setup/plan` takes the answers and returns what would be created or changed: groups, rates, zones,
  methods, pages and settings. It saves nothing.
- `POST /setup/apply` makes those changes in one go, writes them to the change log as "set up by …", and keeps a
  settings archive from before, so the shop can go back.
- Answers are saved as the owner goes (`PUT /setup/answers`). The wizard can be left and picked up again on
  another day or device.
- Each area offers its own preset method (`TaxGroupService::applyProfile`, `Carriers::fromZones`, …). A new
  Setup area in the Application layer only orchestrates; it adds no rules of its own.

**It never overwrites quietly.** Something that already exists (a tax group, a carrier, a page) appears in the
plan as "keep" or "replace", with "keep" chosen. A second run of the setup only adds what is missing.

## The steps

1. **Your business.** Asks once for what settings and invoices both need: legal name, address, email and phone,
   VAT or tax number (checked with VIES in the EU), company number, and bank details. Currency, units and how
   money is written come from the country; the currency is a list, not a typed code.
2. **Who and where you sell.** Consumers, businesses or both. Countries: your own, the EU, Europe, worldwide,
   or a list. This sets `sell_to`.
3. **Tax**, from the profile:
   - **EU.**
     - Groups: Standard, Reduced, Super-reduced, Zero.
     - Rates: every EU country's rates (new data, standard and reduced).
     - Basis: `eu_origin` below the threshold and not in the OSS, otherwise `eu_destination`.
     - Reverse charge is on when selling to businesses.
     - Prices include tax and are shown with it for consumers; with "suffix incl. VAT".
     - Shipping is taxed like the items.
     - The threshold is watched.
     - **Not registered** (small-business exemption): no tax is charged, and invoices carry the exemption note.
       It is prefilled where it is known (DE §19 UStG, AT, FR "TVA non applicable, art. 293 B du CGI", …),
       otherwise typed by the owner.
   - **UK, Switzerland, Norway**: their rates; prices with tax.
   - **Canada**: GST/HST/PST by province. **Australia**: GST 10%. **New Zealand**: GST 15%.
   - **US.**
     - The owner ticks the states where the shop has nexus. The nexus report (114) helps them decide.
     - The wizard offers a tax service if one is installed.
     - Otherwise it sets each state's base rate and says plainly that local rates are missing.
     - Prices exclude tax.
   - **Anywhere else**: one rate the owner types, or none.
4. **Shipping.** One zone for each answer to step 2: home, the EU, the rest of Europe, the world.
   - For each zone, the owner picks a flat price, free above an amount, or prices by weight (a short table).
   - Local pickup is an option.
   - Shipping methods from add-ons (a courier's own prices, such as DPD's) are offered for each zone beside the
     shop's own. The step shows their settings (`Setting`: text, secret, yes/no, number, choice) as the Shipping
     screen does, and uses their `isReady()`.
   - Countries the shop sells to but does not ship to are listed, so the owner either adds them or stops selling
     there.
5. **Payments.**
   - **Bank transfer** takes its IBAN/BIC from step 1.
   - **Cash on delivery** is offered only for home.
   - **PayPal**: its keys are typed here, with a sandbox switch and a "Test the keys" button (PayPalHealth's
     check).
   - **Gateways from add-ons** are listed beside the shop's own. Each shows its settings as the Payments screen
     does, and its `notReady()` says what is missing.
   - A gateway or shipping method may also implement a new `TestsSettings` interface (extension API version 12).
     The step then shows its "Test" button, as PayPal has, so an add-on's keys are checked in the same place.
   - Only the add-ons that are installed are shown. The setup fetches no list of add-ons from anywhere.
   - The wizard cannot be finished with no way to pay.
6. **Legal.**
   - **Pages, made from templates** and filled in with step 1's details:
     - Terms of sale;
     - right of withdrawal, with the model withdrawal form (EU/UK);
     - shipping and payment (zones, prices, times, methods);
     - legal notice (imprint), where the country requires one (DE, AT, …).
   - The privacy policy links to WordPress's own page, with a paragraph about what the shop keeps.
   - Each page is a draft until the owner publishes it, and it says it is a starting point, not legal advice.
   - **Checkout**:
     - a required "I accept the terms of sale" box that links the pages (a setting, on in the EU and UK);
     - the withdrawal notice;
     - the place-order button says that ordering means paying ("Buy now", "Order with obligation to pay"), as
       the EU requires.
   - **The EU defaults the setup turns on**:
     - 14 days to return;
     - the lowest price of the last 30 days shown on discounts;
     - invoices on emails;
     - e-invoices where B2B and the country require them.
7. **Emails.** Sender name and address, and a test email to the owner. The step says whether the email went.
8. **Products**, as now: add one, import, or use the sample products.
9. **Check and open.**
   - The **readiness check** lists what stops the shop from selling, then what is advised. It runs on the server.
   - **Stops**:
     - no way to pay;
     - a country sold to with no shipping;
     - a country sold to with no tax decided;
     - no seller on invoices;
     - in the EU/UK, terms or withdrawal pages unpublished;
     - no product for sale.
   - **Advised**:
     - a cookie consent plugin (not the shop's job);
     - a published privacy page;
     - live keys instead of sandbox ones;
     - the threshold close.
   - **Open the shop**: until then the storefront shows "Opening soon" to visitors. Managers see the real shop,
     so they can place a test order.
   - The check stays on the Status screen after setup and runs again when settings change.
   - Add-ons add their own checks through the extension API, as they add Site Health checks.

## Services the setup contacts

None of them is contacted without the owner asking. They are:

| When | Service | Cost |
|---|---|---|
| An EU VAT number is entered (step 1) | VIES, the European Commission's VAT number check | Free, no account. It is sometimes down for a country; the number is then saved as "not checked" and checked again at the readiness check, without stopping the setup |
| PayPal keys are tested (step 5) | PayPal's API, sandbox or live: it asks for a token | Free to call. PayPal charges fees only on payments |
| An add-on's settings are tested (steps 4–5) | That add-on's provider | The provider's own terms |
| The test email (step 7) | Whatever mail the site uses through WordPress (`wp_mail`): the host's mail server or an SMTP plugin | Free from the shop's side; the plugin calls no mail service itself |
| The readiness check | The site itself: it checks that private download files cannot be opened directly | No outside service |

The setup does not contact:
- any OrillaCart server: there is no account, licence or tracking;
- any service for tax rates. The rates come with the plugin and are updated in its releases.

## Country profiles

A profile holds, for each country:
- currency, units and how money is written;
- the tax system and its rates, with the date they were last checked;
- whether consumer prices include tax;
- the legal facts the setup uses: the right of withdrawal and its days, a required imprint, the exemption note
  for small businesses, e-invoice mandates.

Profiles are data in the code, with tests. Each set of rates has a "checked on" date. Status warns when the rates
are more than a year old, because rates change, and the release checklist reviews them.

The first profiles: the EU-27, UK, CH, NO, US (state base rates), CA, AU and NZ. Every other country gets a
general profile.

### What the data rests on (167, checked 2026-10-25)

- **EU rates**: the Commission's Your Europe VAT table (last checked 2026-07-13). Changes since then come from the
  national authorities: Austria's 4.9% on staple foods from 2026-07-01, Finland's 13.5%, Ireland's 9% for
  catering, Lithuania dropping its 9%, Sweden's 6% on food until 2027-12-31. The Commission no longer publishes
  its yearly PDF.
- **Canada**: the CRA's rate table. **US**: state base rates from the Tax Foundation's mid-2026 table, not from
  each state.
- **Small-business notes**: France, Luxembourg and Portugal prescribe their wording. The others ask only for a
  reference to the exemption, so the note is the usual wording. Belgium, Greece, Hungary and Slovenia's wording
  could not be checked on an official site.
- **E-invoice duties**: the national authorities where they could be read. Several later mandates (Latvia and
  Slovenia in 2028) are enacted but may move.
- **Currency, money format and units**: generated from the Unicode CLDR 46. Bulgaria uses the euro since
  2026-01-01.

The plan shows each country's "checked on" date. A rate the owner changes stays theirs.

## What it cannot promise

- **US local sales tax.** It needs a tax service or rates typed in. The setup says so instead of hiding it.
- **Legal texts** are templates, written in English and translated like the plugin. The setup offers them as a
  start, and the owner or a lawyer has to check them.
- **Country rules that need a public body's own system.** Italy's SdI, for example, is listed as "not handled",
  not ignored.

## For the user to approve

- Profiles beyond the first nine.
- Whether "Opening soon" is part of this work or later.

Everything else is a setting, per the shop's rule on policy choices.
