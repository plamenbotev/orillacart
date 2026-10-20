# 31 · Emails the shop controls

Status: built (2026-10-20), PLAN item 88.

## Decided (2026-10-20)

1. The shop edits the whole template, as Twig, in the admin.
2. The invoice is attached as a PDF, printed with dompdf.
3. The payment reminder goes once, a number of days after the order.
4. Low stock has a threshold for the whole shop, overridden by a stockroom and by a product or variation.

## The emails

| Kind | To | When |
|---|---|---|
| `account_welcome` | customer | the account is made (the visitor chose a password from the emailed link) |
| `payment_reminder` | customer | an order still on hold for a bank transfer or cheque, the shop's days after it was placed (3 by default); once |
| `order_backordered` | customer | an order placed with items on backorder the customer is told of: which come later |
| `tracking_added` | customer | a courier booked the shipment (`ConsignmentBooked`): the link to follow it |
| `payment_failed` | shop | an order's payment failed |
| `refund_made` | shop | money went back for an order, whoever made the refund (the provider's dashboard too) |
| `low_stock` | shop | daily, when something ran low since the last email: every low item, the new ones marked |

Each can be turned off on the Emails screen, as the others can. A reminder is sent only for orders placed in the two
weeks before its day, so turning reminders on never writes to the customers of old orders, and never for cash on
delivery, which is paid when the order arrives (`OfflineGateway::paidFirst()`). A reminder is skipped when the order
was paid or left on hold before it went out.

## Low stock

The threshold is the most particular one set: the variation's, else its product's, else the stockroom's, else the
shop's (`StoreSettings::lowStockThreshold`, 2 by default). Attribute options take the stockroom's or the shop's.
Each stock level of an active stockroom is checked on its own: an item is low in the stockroom that holds few of it.
Only counted stock counts; items always available or not for sale are left out. `LowStock` (Catalog) lists them;
`LowStockAlerts` (Notifications) remembers what the last email named (`AlertedStock`), so an item restocked and low
again is new again.

## Templates

- Every file under `templates/emails/` (as a theme overrides it) can be replaced by the shop's own source, kept in
  `email_templates` and reset to the file at any time. The shop's own comes before every file of the name.
- Emails render in a Twig environment of their own (`EmailTwig`), always in the sandbox (`Twig\Sandbox\Sandbox`,
  strict policy): Twig's tags, filters and tests except `constant`, `source`, `attribute` and calling methods, the
  shop's `__`, `_n`, `money`, `shop_date`, `address_lines`, `prices_include_tax` and `slot`, and the arrays the email
  is given. The plugin's own templates keep to that too.
- Saving checks the edit (`TemplateEditing::check()`): it compiles, and renders once so the sandbox's checks run; a
  missing variable is left to the preview.
- The preview shows the email the template makes, saved or as edited, with the latest order or one the shop names,
  strictly (`Strictness`): a variable the email is not given fails there instead of being logged. Account emails and
  the stock email show made-up details. The frame (`layout`) and the parts (`_order`, `_address`) show in the email
  the shop picks. "Send me a test" mails the preview, "Test:" before its subject, to the user who asks.

## Invoices

The order confirmation and the completed order carry the order's invoice, a refund email its credit note, as PDFs
printed from the invoice page (`templates/invoices/document.html.twig`, with `pdf` set) in the customer's language.
The shop turns it off in the invoice settings (`attachToEmails`). dompdf prints on A4 in DejaVu Sans, embedding only
the letters used, and fetches nothing. A page takes some 20 MB to print: printing asks WordPress for the memory image
work gets (`wp_raise_memory_limit`) and frees dompdf's at once. One that cannot be printed is logged and the email
goes without it.

The release prefixes dompdf with the rest; `scoper.inc.php` rewrites the class names dompdf and php-font-lib build
in strings, and the smoke test prints a Cyrillic PDF with the bundled copy.
