# 61 · What an extension reaches of the shop

Status: built (2026-11-10). PLAN items 374–379.

The trade-accounts extension (a B2B layer an agent wrote from the MCP alone, 2026-11-09) worked, and its own
comments named what it could not do: send an email, read a product's price or name, read a customer, place the
order a quote turns into, and format money without helpers; and the query budget shaped its admin screen more
than its design did. Each is a host function or a limit, not an architectural change, so they are one design.

## Decisions

**`shop.products.find(id)`** (`products: read`): the product as shoppers see it now, from the same offer the
storefront prices: today's price before tax, the regular price while a sale runs, whether it is for sale, how
many can be sold, its variations. A translation reads as its original. PHP add-ons get `Products::find()`.

**`shop.customers.find(id)`** (`customers: read`): who the customer is, their saved addresses, their groups and
how many orders they placed; nothing of their login. PHP add-ons get `Registry::customers()`.

**`shop.email.send(to, subject, html, reply_to?)`** (`email: send`): the body cleaned as a product block is and
written into the shop's own email layout (`emails/extension.html.twig`), from the shop's address, in the site's
language, sent as every email of the shop's is (queued, logged). Five a run: a run that has more pushes jobs.
PHP add-ons get `Registry::emails()`.

**`shop.orders.create(order)`** (`orders: create`): an order placed as the shop places one by hand, through the
same service: priced as checkout prices it (an agreed unit price, else the catalogue's, the extensions'
adjusters applying), stock taken, the checkout's questions answered, on hold until paid unless it says it is,
the customer emailed. What the shop refuses is a refusal the script can catch. Its own permission, not `orders:
write`: an extension approved to mark orders must not gain the power to create them without being asked again.
PHP add-ons get `Registry::orderCreation()`.

**Money in text**: `|number` takes money as it takes a number, and `|amount` writes it as an API wants it
(`"1450.00"`).

**Budgets**: 50 queries a run (was 20: a screen of counts and a list per tab ran out), every `shop.*.find` and
`shop.orders.create` counting as one, and a new budget of 5 emails a run. HTTP calls stay at 5.

**API 22** for PHP add-ons, adding the four.

## Left out

Reading orders by customer, products by category, and stock: each is a query the trade extension did not need.
Sending through the shop's own templates (an order's email with the extension's words in it) waits for an
extension that needs it.
