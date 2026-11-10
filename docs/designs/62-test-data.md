# 62 · Test data

Status: built (2026-11-10). PLAN items 380–385.

Agents and people try the shop out on the live site: a test customer, an order on account, an extension's
checkout. Each record they make looks like a sale. It was emailed (an order's confirmation, its status changes,
a welcome), took the next invoice number, issued real gift cards, went to webhooks and the tax service, and was
counted in the reports. Removing it then left a gap in the invoice series, which many countries do not allow.

## Decisions

**No email to an address no mail can reach.** The mailer is wrapped in a guard: an email to a name reserved for
examples and tests (RFC 2606 and 6761: `example.com`, `example.net`, `example.org`, and names ending `.test`,
`.example`, `.invalid`, `.localhost`) is not sent, logged, and answered as sent, so nothing retries or reports a
failure. The filter `orillacart_undeliverable_address` lets a site add its own, such as its QA domain. No
setting: no real customer can have such an address.

**A test mark on customers and orders.** `create_customer` and `create_order` take `test: true`, as does an
extension's `shop.orders.create`; every order of a test customer is a test order, from checkout too. The mark is
set when the record is made and never later, so a sale never becomes test data. The guard holds every email about
a test order (the shop's own copy too) and every email to a test customer, whatever the address.

**A test order is not a sale.** It takes no invoice or credit note, so the series has no gaps when it goes; it
issues no gift card (the card would be spendable money); webhooks and the tax service hear nothing of it; the
reports keep no facts of it, lifetime values leave it out, and an export leaves it out unless it asks
(`test=yes`). The orders list and the order and customer screens show a "Test" badge, and `test=yes|no` filters
the list. Extensions see `test` in the order map and decide for their own records.

**One call removes it.** `DELETE /test-data` (`remove_test_data`, asks first) removes every test order, an open
one cancelled first so its stock comes back, then every test customer's account and what the shop kept of them.
A sale cannot be removed this way. `GET /test-data` counts what there is.

## Left out

A shop-wide "hold all mail" switch for a staging copy of a live site: a separate feature. Marking an existing
record as test data afterwards: it would let a sale leave the books.
