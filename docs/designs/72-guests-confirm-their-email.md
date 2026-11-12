# 72 · Guests confirm their email where too many orders wait

Status: built (2026-11-12). PLAN items 624–630.

Orders waiting for a transfer hold their stock, so one shopper may have only a few at once
(`unpaid_orders_each`, 3). A customer is counted by account. A guest is counted by the address they order from,
never by the email as typed: anyone can type a new one for every order, or someone else's to lock them out. The
cost was that guests who share an address (an office, a campus, a carrier's NAT) fill it between them, and the
fourth was refused whoever they were.

## Decisions

**A guest at a full address is asked to confirm their email.** Placing the order is refused with
`orillacart_confirm_email` and a link goes to the email they gave. Followed, it shows the mailbox is theirs; the
order placed again is counted by that mailbox, up to the same number, and no longer by the address. Nobody else is
asked anything: below the limit, with an account, or paying online or on delivery, checkout is as before.

**The mailbox, not the address as written.** The count is by `EmailAddress::key()`: `ada+shop@` is `ada@`, Gmail's
dots are ignored. Only orders placed with a confirmed email count for it, so orders someone placed in that email
from elsewhere never keep its owner out. An order keeps a key of the mailbox where it kept a key of the address
(`Order::callerKey()`), while it waits; no new column.

**The link works wherever the mail is read.** A guest orders on a laptop and reads mail on a phone, on another
network. So the link is not tied to a browser or an address: its token carries a key of the mailbox and of the cart
that asked (which names neither), when it was asked for, and the shop's signature, and works for 24 hours. The page
it opens has one button, "Confirm my email": a mail program that only fetches links confirms nothing. Pressed
(`POST /checkout/email-confirmation`), the shop keeps the key until the 24 hours end, and the browser with that
cart places the order again. The cart's cookie is the browser's proof: another browser typing the same email has
another cart and is asked itself, so a confirmed email cannot be used by someone who merely knows it. A cart
keeps its token after an order, so the next orders that day need no new link.

**How many.** Links are counted: 5 a day from one address (`email_confirmations`), which is how many mailboxes one
address may order by a day, and 3 a day to one email from everyone (`email_confirmation_address`), so ordering is
no way to flood someone. Past either, the guest is refused as before. Both change with `orillacart_rate_limits`.

**A setting.** `guests_at_unpaid_limit`: `confirm_email` (the default) or `refuse`, beside the limit on the
Settings screen, shown while there is a limit.

## Not done

Confirming every guest's email before any order: it costs every guest a trip to their mailbox, and one person has
as many mailboxes as they like, so it limits nothing the address count does not.
