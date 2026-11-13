# 75 · Shoppers' agents: the Universal Commerce Protocol

Status: built (2026-11-13). PLAN items 658–665. The guide for owners and agents' developers is `docs/shoppers-agents.md`; what comes after, phase by phase, is `docs/plans/shoppers-agents.md`.

A manager's agent works the shop through its MCP server (design 49), and can extend it (design 56). A shopper's
agent could not: it read the storefront's HTML. Agents that shop for people (Google's, and any agent that speaks
the protocol) now look for a shop's `/.well-known/ucp` and buy through its endpoints. Two protocols are in use:
OpenAI and Stripe's Agentic Commerce Protocol, whose ChatGPT checkout was retired in March 2026 and whose payment
step is Stripe's delegated token, and the Universal Commerce Protocol (UCP, Google with Shopify and retailers,
release 2026-08-25), which covers catalogue, cart, checkout, identity and orders over REST and MCP, and lets a shop
without delegated payments hand the buyer over to its own checkout. The shop speaks UCP.

## Decisions

**UCP 2026-08-25, as a business.** `/.well-known/ucp` publishes the profile: the `dev.ucp.shopping` service over
REST (`/wp-json/orillacart/v1/ucp`) and MCP (`/wp-json/orillacart/v1/ucp/mcp`), the capabilities
`dev.ucp.shopping.catalog.search`, `…catalog.lookup` and `…checkout`, no payment handlers yet, and the shop's
signing key. Served with `Cache-Control: public, max-age=300` and an ETag, as the spec asks. The owner switches it
on in Settings › Integrations ("Shoppers' AI agents"); off, the profile and endpoints answer 404.

**The agent's profile is fetched, safely, and capabilities intersected.** Every request names the agent's
profile (`UCP-Agent: profile="https://…"`, or `meta["ucp-agent"].profile` over MCP). The shop fetches it with
WordPress's safe HTTP (no private or special-use addresses), at most 128 KiB, keeps it an hour, and answers with
the capabilities both sides have, at the highest version both name. An unreachable profile is 424
`profile_unreachable`, a malformed one 422 `profile_malformed`, no common version 422 `version_unsupported`.

**The catalogue is the shop's listing.** `search_catalog` (REST `POST /catalog/search`) is `ShopListing`'s search,
as `shop.catalog.list` (design 74): words, categories, price in minor units, a cursor, pages of up to 24; only what
shoppers may find, priced as shown to a visitor. `lookup_catalog` (`POST /catalog/lookup`) reads products and
variants by id. Products carry UCP's shape: `price_range` and `list_price_range` in minor units, `variants` (the
first the featured one, the simple product its own variant), `media`, `options`, `url`, `availability`. A
variant's id is what checkout takes: `prod_12` for a simple product, `var_34` for a variation.

**A checkout session is one of the shop's carts.** Creating one makes a cart the shop prices, with every rule,
coupon, line type, stock check and shipping quote its own checkout has (`CartService`, `CheckoutService::summary`),
and a session row (`agent_checkouts`): the cart, the buyer, the address, the shipping option chosen, the status,
the agent's profile, an expiry six hours on. Update (`PUT`, the whole resource) sets the lines, buyer and
fulfilment. Totals, line totals and fulfilment options are the summary's, in minor units; what cannot be ordered is
a message with UCP's codes (`out_of_stock`, `item_unavailable`, `quantity_adjusted`, `field_required`), never an
order changed silently.

**Payment is handed to the buyer, at the shop's checkout.** With no delegated payment (no handler is declared), a
session with everything set is `requires_escalation`, with a `requires_buyer_input` message and a `continue_url`:
a link, signed and for this session alone, that gives the cart to the buyer's browser, fills the checkout with the
buyer and the address the agent gave, and opens it. The buyer reviews and pays as any customer does: the shop's
terms, its payment methods, 3-D Secure, its confirmation email. The order is the shop's own; the session becomes
`completed` with `order { id, permalink_url }` when that cart is ordered. `complete_checkout` answers the
escalation again rather than placing anything: an agent never pays for a person here.

**A buyer who logs in takes the session along.** Logged in when they open the link (or logging in at checkout), the
buyer's cart becomes their account's, as every visitor's does: joined into the cart their account held, under a new
token (`CartClaimed`). The session follows that cart, and its order completes it. The account's cart may hold what
the buyer put in it themselves, so from then on the agent sees the session as it last saw it, waiting for the
buyer, and changes to it are refused (`checkout_closed`); cancelling ends the session and leaves the cart theirs.
The link then opens their checkout, which starts with the agent's address when the account has none.

**Retries are safe.** `Idempotency-Key` (REST) or `meta["idempotency-key"]` (MCP) is required on what changes a
session; the shop keeps each answer a day, gives it back for the same key and body, and answers 409 for the same
key with another body. `Request-Id` is echoed.

**Held as the shop's other public writes are.** The endpoints are public and take no nonce (agents call server to
server; a session is its own cart, never the caller's cookies' or account's, so there is nothing for a forged
request to act as). Changes count per caller (the `ucp` guard: 1 200 in 10 minutes, as an agent platform calls for
many shoppers from few addresses); reads are not counted. A session's buyer and address are sealed, and forgotten
a day after it ends. An agent's cart sends no cart reminder: the buyer asked for none.

## Later

Each is a phase of [the implementation plan](../plans/shoppers-agents.md), with its PLAN items.


- Payment handlers: Google Pay and AP2 mandates, so an agent completes with the buyer's consent proven; Stripe's
  delegated token for ACP's checkout.
- Identity linking (`dev.ucp.common.identity_linking`): the shop's OAuth (design 49) for a customer's account,
  their saved addresses and group prices.
- Order capability and webhooks to the agent: status, shipments, returns.
- The cart capability, and extensions' own capabilities.
