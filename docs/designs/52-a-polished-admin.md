# 52 · A polished admin

Status: agreed (2026-11-05). PLAN items 299–305 (steps 1–7).

The admin works and does a great deal, but it reads as twenty screens that each grew on their own: a flat menu of
twenty entries, forms that stretch every field to the same width, one-column pages that scroll for six thousand
pixels, statuses in plain text. This design makes it look and feel like one product: simple, calm, and obvious
where to go. It stays inside wp-admin and `@wordpress/components`, which is what a WordPress user expects, and
polishes within those bounds rather than importing a foreign look.

What was looked at: every Shop screen of the demo shop at 1440 px (orders, an order, customers and a customer,
coupons, price rules, tax, shipping, payments, emails, stock, jobs, status, integrations, settings, setup) and
the product editor.

## What is wrong today

1. **Navigation.** "Shop" has twenty submenu entries in one flat list; Attribute sets, Forms and Price rules sit
   between Tax and Stockrooms; Setup is last; Settings is one entry but Tax, Shipping, Payments, Emails and
   Integrations are settings too. Products live under their own top-level menu. There is no home: the first entry
   is Orders, and nothing says what needs the owner today.
2. **Forms.** Every field is as wide as the card's 760 px: a three-letter currency code, a postcode and a
   four-paragraph textarea are the same width. Every field has a help paragraph; the Tax and Shipping pages are
   walls of text and inputs with no grouping. Settings is one card of some forty fields with no sections or tabs.
3. **Page headers differ.** Orders: title with buttons inline. Reports: no title, the page starts with filters.
   Stock: tabs above the title. Setup: title then a step list. Customers: title, then settings cards, then the
   list, with the customer's card opening inline between them.
4. **An order** is one column of equal cards, 6 600 px tall: summary, items (each item's quantity and price as
   stacked labelled fields, the labels repeated per item), charges, price changes, payment, status, shipments,
   history, customer, account, emails. The primary actions (mark as paid, change status) are buried mid-page.
5. **Statuses are words.** "On hold", "Processing", "Cancelled", "Active" are plain text in the same grey as the
   rest; the Attention column shows nothing until it shows red text. Nothing can be scanned.
6. **Two kinds of table.** DataViews (orders, coupons, customers) and hand-written tables (stock, jobs, API keys,
   filters) with their own header style, striping and wrapping; the API keys table wraps a date onto five lines.
7. **Empty and loading states** are blank space, a sentence, or nothing. No skeletons; saving shows little.
8. **Help text everywhere.** The shop's voice is good, but a paragraph under every field makes a screen slow to
   read. Professional admin UIs show one short line and keep the rest a click away.

Two bugs met on the way: opening a customer's card on the Customers screen showed "Something went wrong. The error
has been logged." (the card still opened), and `bin/demo-shop.php` stops with a 409 on a variation's stock when run
on a site that already has the demo.

## Principles

- **One system.** Every screen is built from the same few parts: a page header, sections, fields, a table, a
  badge, an empty state. No screen styles itself.
- **Simple first.** The common case is on the screen; the rare case is behind "More" or an expander. A field's
  width says what goes in it. Help is one line; the paragraph opens on demand.
- **Scannable.** Colour and weight mark what matters: statuses as badges, attention as an icon, money right-aligned
  and tabular, the primary action blue and alone.
- **WordPress native.** `@wordpress/components`, `@wordpress/icons`, DataViews, the admin colour scheme variables.
  It should look like WordPress 7 made a shop, not like a plugin brought its own framework.
- **Measured.** Each step is checked on the demo shop at 1440 px and 1024 px, with screenshots in the PR; the
  e2e admin screens test keeps a screenshot set so regressions show.

## The plan

### 1. The design system (foundation, everything else builds on it)

Shared parts in `assets/admin/shared/ui/`, one stylesheet `admin.css` with tokens (spacing 4/8/12/16/24/32,
one radius, the WP greys and semantic colours), replacing the ad-hoc rules in `screen.css`:

- `Page`: title, optional description (one line), primary and secondary actions on the right, optional tabs under
  the title, optional back link above it. Every screen uses it, so headers are the same everywhere.
- `Section`: a card with a title, a one-line description and an optional action in its header; the body lays
  fields out in a 12-column grid.
- `Field` sizing: `size="xs|s|m|l|full"` (6, 12, 24, 40 em, full) on every control; the shared `SettingFields`
  choose by type (code, number, money, postcode, email, text, textarea). `Row` puts fields side by side.
- `Help`: one line under a field; a longer text sits behind an "i" `Tooltip` or a "Learn more" expander.
- `Badge`: `tone="neutral|info|success|warning|danger"`; a `StatusBadge` maps order, payment, shipment, job,
  coupon and rule statuses to tones in one place.
- `EmptyState`: icon, one sentence, one action. `Skeleton` rows for tables and cards while loading.
- `Toast` (Snackbar) for "Saved", in one place; `useSave` returns busy/saved/error so screens stop hand-rolling it.
- `DataTable` for the few tables that are not DataViews (stock sheet, jobs' lanes, keys): DataViews' header and
  row styles, right-aligned numbers, dates that do not wrap.

### 2. Navigation

- The Shop menu becomes eight entries: **Home, Orders, Customers, Catalog, Marketing, Reports, Settings, System**.
  Products keep their own top-level menu (WordPress's post type) but Catalog links to them.
- Each grouped screen has tabs (`Page` tabs, the URL keeps `&tab=`):
  - Catalog: Stock, Stockrooms, Attribute sets, Price rules, Import and export.
  - Marketing: Coupons, Automatic discounts, Gift cards, Newsletter (from Customers).
  - Customers: the list first; Accounts and groups as a tab.
  - Settings: General, Tax, Shipping, Payments, Emails, Forms, Integrations, Legal.
  - System: Status, Background jobs, Change log.
- Setup leaves the menu: Home shows "Set up the shop: 7 of 10 steps" with a button while it is unfinished, and
  Settings keeps a "Run the setup again" link after.
- Add-ons' pages (the extension API's `AdminPage`) say which group they join, default System.

### 3. Home

A screen the owner opens in the morning:

- Four tiles for today and the last seven days: net sales, orders, average order, visitors who bought (from the
  reports' figures, one request).
- **Needs you**: orders on hold past a day, orders with attention (failed payment, refund pending), items below
  their low-stock mark, failed jobs, status checks that are not Good, setup steps left. Each line links to the
  place. Empty: "Nothing needs you."
- Recent orders (five) and a small sales sparkline.
- Add-ons add a tile or a "needs you" line through a slot.

### 4. Lists

- Statuses as badges in every DataViews list; the Attention column becomes an icon with the reason as tooltip,
  and the row's left edge carries the tone.
- Row click opens the item (DataViews' `onClickItem`); the kebab keeps the rest.
- Saved views ("On hold", "Needs attention", "Today") as filter chips above the orders list, remembered per user.
- Bulk actions on orders (change status, export, print) and coupons (activate, deactivate).
- Money in tabular figures, right-aligned; dates relative within the week ("Yesterday, 5:52 PM"), full after.
- Customers: the list is the screen; the customer card opens as its own view with a back link, like an order.

### 5. The order

- A sticky header: back link, "Order #165", status badge, "Placed Oct 23, 5:52 AM · Bank transfer · Not paid",
  then the actions: **Mark as paid** (primary when unpaid), Change status, Send email, More (print, refund, erase).
- Two columns at 1280 px and wider, one below:
  - Main: Items (a table: product, quantity, unit price, total, with quantity and price edited in the cell while
    the order can change; charges and price changes as rows of the same table with an "Add" menu), Shipments
    (what is left to send, parcels sent with tracking), Timeline (history and notes merged, newest first, with
    the note box on top).
  - Side: Payment (what is owed, paid, refunded; refunds inside), Customer (name, email, phone, a link to their
    card), Addresses (billing and shipping as text with Edit opening a modal), Account, Emails sent.
- The same shape for a customer's card: header with name and lifetime figures, main column with orders and
  notes, side with addresses, groups, exemptions, newsletter.

### 6. Settings

- Tabbed settings screen (step 2); within a tab, `Section`s with a short title and one-line description:
  General splits into Shop (name, address), Currency and units, Selling to, Pages, Storefront, Downloads,
  Archive. Tax: Where tax is charged, Prices, Businesses, Exemptions, then Rates and Nexus as their own tabs.
- Field widths by content; related small fields in a row (currency, sign, position; weight and dimension unit;
  country, state, postcode).
- Advanced or rare options under a "More options" expander at the bottom of a section (Price decimals, the
  text after prices, the tax number asking).
- One Save per tab, sticky at the bottom when the form is dirty, with the leave guard already there.
- Shipping and Payments: a list of carriers/methods (name, active badge, order, a summary line) with the
  carrier's form opening on its own; today every carrier's whole form is open on one page.

### 7. The finishing pass

- Empty states and skeletons on every list and card; every destructive action through `useConfirm` with the
  consequence in a sentence.
- Keyboard: focus visible, tab order, the page's first field focused on open where it helps.
- 1024 px and 782 px (WordPress's mobile breakpoint): the two columns fold, tables scroll, headers wrap once.
- One icon set (`@wordpress/icons`) for actions and empty states; no dashicons in React.
- Contrast and type: body 13 px as wp-admin, titles 20/16/13 with the same weights everywhere; help text the
  WP grey (`#757575`) that passes contrast on white.
- Screenshots of every screen in `tests/e2e/admin-screens.spec.ts`, compared on CI with a small tolerance, so
  a change to the system shows on every screen.

## Order and size

| Step | What | Size |
|---|---|---|
| 1 | Design system parts and tokens; migrate two screens (Coupons, Payments) to prove it | L |
| 2 | Navigation: eight entries, tabbed group screens, setup out of the menu | M |
| 3 | Home | M |
| 4 | Lists: badges, attention, row click, saved views, bulk actions, customer as a view | M |
| 5 | The order and the customer's card | L |
| 6 | Settings: tabs, sections, widths, expanders, carriers and methods as lists | L |
| 7 | Finishing pass and screenshot comparison on CI | M |

Steps 1 and 2 first; 3 to 6 can go in any order after them; 7 last. Each step is one PLAN item: 299 to 305.

## Not in this design

The storefront's blocks and templates (design 50), the product editor's block-editor panels (they follow the
editor's own design), and the emails' look (design 47's templates).
