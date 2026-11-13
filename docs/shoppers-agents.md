# Shoppers' AI agents (UCP)

An AI agent that shops for a person (an assistant asked "find me a desk lamp under €50 and order it") finds the
shop's products and prepares the order through the [Universal Commerce Protocol](https://ucp.dev), release
2026-08-25. The person always pays at the shop's own checkout: the agent hands them a link. This is the shoppers'
side; the manager's side, an assistant that runs the shop, is [the assistant](assistant.md). Design 75 has the
reasons.

## Switching it on

Shop › Integrations › Shoppers' AI agents. Off by default; while off, the profile and every endpoint answer 404.
The card shows where agents find the shop: `https://your-site/.well-known/ucp`.

## What an agent can do

| Operation | REST (`/wp-json/orillacart/v1/ucp`) | MCP tool (`/wp-json/orillacart/v1/ucp/mcp`) |
| --- | --- | --- |
| Find products | `POST /catalog/search` | `search_catalog` |
| Read products by id | `POST /catalog/lookup` | `lookup_catalog` |
| Start a checkout | `POST /checkout-sessions` | `create_checkout` |
| Read it | `GET /checkout-sessions/{id}` | `get_checkout` |
| Change it (the whole session) | `PUT /checkout-sessions/{id}` | `update_checkout` |
| Ask to complete it | `POST /checkout-sessions/{id}/complete` | `complete_checkout` |
| Cancel it | `POST /checkout-sessions/{id}/cancel` | `cancel_checkout` |

- **The catalogue is the shop page's.** The same search, only what shoppers may find (published, not behind a
  password, not "ask for the price"), priced as a visitor sees prices. Amounts are in the currency's smallest unit
  (`1250` is €12.50). A product's variants are its variations; a simple product is its own. A variant's id
  (`prod_12`, `prod_30_var_41`) is what a checkout takes.
- **A checkout session is one of the shop's carts.** Items are added as a shopper adds them, refused for the same
  reasons (out of stock, not for sale, a cart rule), and priced by the shop's own checkout: taxes, discounts, fees,
  the ways to ship to the address given. What cannot be ordered comes back as a message on its line
  (`out_of_stock`, `item_unavailable`), never as an order changed silently.
- **The buyer pays at the shop.** Once the session has the items, the buyer's email, and where it ships with a way
  to ship chosen, it is `requires_escalation` with a `payment_required` message and a `continue_url`. That link
  gives the cart to the buyer's browser and opens the shop's checkout, filled with the buyer and address the agent
  gave. They review it, choose how to pay, and place the order as any customer does: the shop's terms, payment
  methods, 3-D Secure, confirmation email. The session is then `completed`, with the order's id and receipt link.
  `complete_checkout` never pays: it answers where the buyer does.

## What an agent sends

- `UCP-Agent: profile="https://…"` on every REST request (`meta["ucp-agent"].profile` in an MCP call). The shop
  reads that profile (https only, never a private address, at most 128 KiB, kept an hour) and answers with the
  capabilities both name at version 2026-08-25. A profile that cannot be read is 424 `profile_unreachable`, one
  that is not a profile 422 `profile_malformed`; a profile at another version gets `capabilities_incompatible`.
- `Idempotency-Key` on every REST change (in MCP, `meta["idempotency-key"]`, required to complete or cancel). The
  same key and body is answered as before, for a day; the same key with another body is 409.
- `Request-Id` is echoed back.

## Limits and what is kept

- Changes to sessions count per caller: 1 200 in 10 minutes (an agent platform calls for many shoppers from few
  addresses). Reads are not counted. A site changes the limit with `orillacart_rate_limits`, guard `ucp`.
- A session lasts six hours. Its buyer's details and address are kept sealed in `agent_checkouts`, and forgotten a
  day after it ends; the cart and any order are the shop's, as for every customer.
- The endpoints act for no one: a session is its own cart, never the caller's cookies' or account's, so they take
  no nonce (agents call server to server) and need no account.
- Opening the `continue_url` gives that cart to the browser, in place of the cart it held.

## Not yet

Payment the agent completes with the buyer's proven consent (Google Pay, AP2 mandates; Stripe's delegated token
for the Agentic Commerce Protocol), signing into the buyer's account (identity linking), order updates sent to the
agent, and the cart capability.
