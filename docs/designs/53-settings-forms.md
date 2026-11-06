# 53. Settings forms

Status: built (2026-11-06). PLAN items 308–309.

Design 52 regrouped the top of the General settings page, but the page below it kept nine older cards, each with
its own save button, every input as wide as the page, and narrow fields whose help text ran down in a tower.

## What a settings form looks like

- **A field's size narrows the control, not its label or help.** `Field size="xs"` makes a 7rem input; the label
  and the help under it take the width of the row, with the help capped at about 64 characters so a sentence
  stays readable. Fields with help spread out in a `Row`; a pair of short fields without help stays compact.
- **A control outside a `Field` is still bounded**: in a section it is at most `l` wide (32rem), so a phone
  number or an invoice prefix never spans the page. A control that wants the whole width says `size="full"`.
- **Checkboxes and radio groups keep their distance** from what follows, with or without a `Field`.
- **An introduction is the section's description**, not a paragraph in its body; the explanation of a choice is
  the control's `help`.
- **One page is one form with one save bar.** Messages are toasts; errors sit at the top of the page.

## The Settings tabs

| Tab | Holds |
| --- | --- |
| General | Currency and units, Other currencies, Where the shop is, Selling, Orders and returns; the settings file below the save bar, as it is not part of the form |
| Pages and legal | Shop pages; Legal pages and what checkout asks |
| Invoices | Seller, On the invoice, Numbering, Sending |
| Storefront | Reviews, Search engines and sharing, Shoppers, Store notice |
| Tax, Shipping, Payments, Emails, Forms, Integrations | as before |
| Advanced | Sending downloads |

Shop filters and the search engine are about the catalog and move to **Catalog › Filters and search**.

Every tab is served by the `settings` script, mounted by the element id the registrar prints, as the price-rules
script already serves two pages. Storefront's choices used to save on every click; they now save with the tab's
button like every other setting, so a change can be undone before it is kept.

## What was checked

The look spec (`tests/e2e/admin-look.spec.ts`) opens the five new tabs in their frame. Each screen has its own
Jest tests over a shared mock of the settings API (`assets/admin/settings/testing.ts`); the Storefront tests
show that a click sends nothing until the button. The menu's tab order is asserted in `SettingsRestTest`.
