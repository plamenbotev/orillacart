# Shoppers' agents: the implementation plan

The whole path to a shop that AI agents buy from for people: what [design 75](../designs/75-shoppers-agents.md)
built, what it left, and every phase after it, in the order they are worth building. Each item is a PLAN.md line
(§ "Shoppers' agents: the Universal Commerce Protocol"); its mark there is the truth, this file the reasons and the
detail. Written 2026-11-13 against UCP 2026-08-25.

Marks: ✅ done · ⬜ to do · 📝 needs a design approved before building · ➖ not now

## Where it stands

Phase 1 is built (PLAN 658–665): the profile at `/.well-known/ucp`, the catalogue's search and lookup, checkout
sessions over REST and MCP, each one of the shop's carts. The buyer pays at the shop's own checkout through the
session's `continue_url`, and the order placed from the cart completes the session. Idempotency keys,
`Request-Id`, a rate limit on changes, sealed buyer details forgotten a day after a session ends. The owner switches
it on in Shop › Integrations. Tests: `tests/Integration/ShoppersAgentsTest.php`, `tests/e2e/agents.spec.ts`.

What a shop owner can promise today: an agent finds the products and prepares the order exactly as the shop
prices it, and the buyer pays on the shop's checkout. What it cannot: an agent that pays, a buyer's account, or
the agent hearing what happens to the order after.

## Principles every phase keeps

1. **The shop decides, the agent asks.** Prices, taxes, stock, rules and shipping are the shop's own code paths
   (`CartService`, `CheckoutService`), never a second implementation for agents.
2. **The buyer consents, provably.** Nothing is paid without either the buyer at the shop's checkout or a
   mandate the shop verifies (phase 6). An agent's word is never consent.
3. **Act for no one by default.** An unauthenticated agent request reaches only what a visitor could, and its
   own sessions. A buyer's account needs identity linking (phase 4).
4. **Off until the owner says.** Each capability that changes what the shop risks (payment handlers, identity) is
   its own switch, with what it risks said beside it.
5. **The spec's words.** Codes, statuses and field names are UCP's; where the shop adds meaning it uses UCP's
   extension mechanism, not new fields.

## Phase 1b: gaps in what is built (do first)

| | # | P | Item |
| --- | --- | --- | --- |
| ✅ | 666 | P1 | **A signed-in buyer opening continue_url lost the session.** `CartService::find` merged a guest cart into the customer's own and deleted it (`loggedIn`), or rekeyed it: the session's sealed token found nothing. Done: `loggedIn` announces `CartClaimed(from, into, customer)`; the session follows the cart it became (its order completes it), keeps no token, and answers as the agent last saw it (`shown`, sealed), as the account's cart may hold the buyer's own things; changes are refused (`checkout_closed`), cancel leaves the cart; the link opens the buyer's checkout, prefilled by cart id when the account has no address. Tests: integration (account cart merged) and end to end (customer logged in opens the link twice and pays) |
| ⬜ | 667 | P2 | **The handover keeps the browser's own cart.** Today the link replaces the cart cookie: what the buyer had is no longer theirs to see. Do as the reminder link does (`CartService::recover`): a browser with a filled cart gets the agent's items joined to it, and the session follows (666) |
| ⬜ | 668 | P2 | **The agent's context is honoured.** `context.currency` prices in that currency when the shop offers it (`Currencies::isOffered`), `context.language` reads products in it (`ContentLanguages`), `address_country` estimates tax and shipping before an address is given. Today the shop's default currency and language are used whatever the agent asks |
| ⬜ | 669 | P2 | **Orders carry where they came from.** An order placed from an agent's session records the agent's platform (its profile's host) as the order's source: a column of the orders list and the export, a filter, a line on the order's screen ("Prepared by an AI agent: …"), and a figure on Home. UCP's `attribution` field fills it when the agent sends one |
| ⬜ | 670 | P3 | **Checkout questions the agent cannot answer.** Add-ons' and forms' checkout fields (`AskedField`) come back as a `requires_buyer_input` message naming each, so the agent tells the buyer what the shop will ask; answers given in a UCP extension (`dev.orillacart.checkout_fields`) are kept and filled in at checkout |
| ⬜ | 671 | P3 | **Personal data.** Sessions are in WordPress's exporter and eraser by email (`ExtensionPersonalData`'s pattern), though they last at most 30 hours |

## Phase 2: trust between the shop and agents

Without it the shop cannot tell one agent from another, and agents cannot tell the shop's webhooks from forgeries.
Phases 4 to 6 need it.

| | # | P | Item |
| --- | --- | --- | --- |
| ⬜ | 672 | P2 | **The shop's signing key.** An ES256 key pair made when selling through agents is switched on, the private key sealed (`SealedValues`), the public one in the profile's `keys[]` (RFC 7517 JWK, `kid`, `use: sig`). Rotation keeps the old key published for a week. A WP-CLI command rotates it |
| ⬜ | 673 | P2 | **Verifying agents' signatures (RFC 9421).** Requests that carry `Signature-Input`/`Signature` are verified against the agent profile's `keys[]` (EC P-256/P-384, Ed25519), covering the method, authority, path, query, `content-digest` (RFC 9530, checked against the body), `content-type`, `ucp-agent` and `idempotency-key`. A request whose every signature fails is 401. The owner chooses: signatures checked when present (default), or required |
| ⬜ | 674 | P3 | **Which agents.** Shop › Integrations lists the platforms that made sessions (by profile host, with counts and the last call), and the owner may block one or allow only some. A blocked platform is 403 with `code: platform_blocked` |
| ⬜ | 675 | P3 | **Versions.** The profile's `supported_versions` when the shop speaks more than one release; the negotiation picks the highest both have (today only 2026-08-25). A conformance run against UCP's published schemas (JSON Schema validation of every answer in the integration tests) |

## Phase 3: carts and the catalogue in full

| | # | P | Item |
| --- | --- | --- | --- |
| ⬜ | 676 | P2 | **The cart capability** (`dev.ucp.shopping.cart`): `create_cart`, `get_cart`, `update_cart` (whole), `cancel_cart`, REST and MCP, each one of the shop's carts with estimated totals; `create_checkout` with `cart_id` starts from it (the cart's lines win, the conversion is idempotent, the two stay linked, the cart is cleared when the order is placed) |
| ⬜ | 677 | P3 | **The catalogue's filters and options.** Search by the shop's own filters (UCP extension of the search with `ShopListing`'s facets), in-stock only, sort; products carry `options` (attribute names and values), each variant its option values, `rating` from reviews, every image as `media`, `categories` by path |
| ⬜ | 678 | P3 | **Bundles and personalised products.** Line items that carry a bundle's choices or a form's answers (a UCP extension of the line item), so what the shop's product page can put in a cart an agent can too; until then such products come back `item_unavailable` with a message saying the buyer orders them on the product's page (`continue_url` to it) |

## Phase 4: the buyer's account (identity linking)

The shop already has an OAuth server for the manager's assistant (design 49). A buyer links their account so the
agent sees their prices, addresses and orders.

| | # | P | Item |
| --- | --- | --- | --- |
| 📝 | 679 | P2 | **Design.** Which of the OAuth server's parts serve customers (today it issues managers' tokens): a customer grant of its own, its consent screen, scopes `dev.ucp.shopping.checkout:manage` and `dev.ucp.shopping.order:read`, the token's audience the UCP endpoints, and what a linked session may read |
| ⬜ | 680 | P2 | **The capability** (`dev.ucp.common.identity_linking`): authorization code with PKCE S256 only, `iss` in the authorization response (RFC 9207), exact redirect URIs, revocation (RFC 7009) that ends a refresh token's access tokens, the metadata UCP lists (`scopes_supported`, `code_challenge_methods_supported`, `authorization_response_iss_parameter_supported`) |
| ⬜ | 681 | P2 | **A linked session is the customer's.** `Authorization: Bearer` on a request makes its session's cart the customer's (`CartOwner` with the customer id): their group prices, tax exemption, saved addresses as the buyer and destinations, store credit. 401 `identity_required` for a missing or expired token, 403 `insufficient_scope` with the scopes needed |
| ⬜ | 682 | P3 | **The customer's own account screen** lists the agents they linked, with when each last acted, and unlinks one |

## Phase 5: after the order

| | # | P | Item |
| --- | --- | --- | --- |
| ⬜ | 683 | P2 | **The order capability** (`dev.ucp.shopping.order`): `GET /orders/{id}` (and MCP `get_order`) for the orders an agent's sessions placed, or a linked buyer's (phase 4, `order:read`). The order as UCP writes it: `checkout_id`, `permalink_url`, line items with `quantity { original, total, fulfilled }` and status, `fulfillment.expectations` and the append-only `events` from the shop's shipments and tracking (`ShipmentTracking`), `adjustments` from refunds, returns and cancellations, totals |
| ⬜ | 684 | P2 | **Webhooks to the agent.** When the agent's profile gives `config.webhook_url` for the order capability, the shop POSTs the whole order on each change (placed, paid, shipped with tracking, delivered, refunded, returned, cancelled), with Standard Webhooks headers (`Webhook-Id`, `Webhook-Timestamp`) and `UCP-Agent`, signed with the shop's key (RFC 9421 with `Content-Digest`, phase 2), through the shop's webhook delivery with its retries (design 49's `WebhookSender`) |

## Phase 6: the agent pays, with the buyer's consent proven

The step that makes "order it for me" one sentence. It moves money on an agent's request, so it is designed before
it is built, and each handler is its own switch.

| | # | P | Item |
| --- | --- | --- | --- |
| 📝 | 685 | P1 | **Design.** What the shop accepts as the buyer's consent (an AP2 mandate it verifies, a wallet's token the buyer approved in the wallet), what it never does without (the shop's terms accepted, the exact total), limits (an order total above the owner's limit escalates to the buyer), 3-D Secure and other challenges (escalation with `continue_url`, the session resumed), fraud signals (`dev.ucp.buyer_ip`, `user_agent`) to the gateway, and how a refund or a dispute reaches the agent (phase 5) |
| ⬜ | 686 | P1 | **Payment handlers in the profile.** The `payment_handlers` registry from the gateways the owner switched on for agents, filtered by the cart (currency, country, amount), each with its id, version and config; `complete_checkout` takes an instrument only of an advertised handler |
| ⬜ | 687 | P1 | **Placing the order from the session.** `complete_checkout` with a valid instrument: `complete_in_progress`, `CheckoutService::placeOrder` with the session's cart, buyer and address (as the shop's checkout places one, with its terms and emails), the credential passed to the gateway and never echoed or logged; `completed` with the order, or `requires_escalation` when the gateway asks for the buyer (3-D Secure), or a recoverable message when it declines |
| ⬜ | 688 | P2 | **Google Pay** (`com.google.pay`) through the shop's Stripe gateway: the handler's config (gateway `stripe`, the publishable key, the merchant's name and country), the token confirmed as a Stripe PaymentMethod |
| ⬜ | 689 | P2 | **AP2 mandates** (`dev.ucp.common.payment.ap2_mandate`): the mandate verified against the agent's keys, its amount, currency, merchant and expiry checked against the session before anything is charged |
| ⬜ | 690 | P3 | **More handlers** as gateways offer agent tokens: PayPal's, and Shop Pay's where it opens to other merchants |

## Phase 7: the other protocol (ACP)

OpenAI and Stripe's Agentic Commerce Protocol (spec 2026-04-17): its checkout sessions, product feed and Stripe's
Shared Payment Token. Since March 2026 ChatGPT takes merchants' feeds and sends buyers to the merchant's checkout,
which phase 1 already offers; the checkout API matters again only where a platform completes payment in ACP.

| | # | P | Item |
| --- | --- | --- | --- |
| ➖ | 691 | P3 | **The product feed** in ACP's format, pushed to the platforms the owner connects (the catalogue of 660) |
| ➖ | 692 | P3 | **ACP's checkout endpoints** as a second binding over the same sessions (`AgentCheckouts`), with Stripe's Shared Payment Token as a handler (phase 6) |

## Phase 8: extensions and agents

| | # | P | Item |
| --- | --- | --- | --- |
| ⬜ | 693 | P3 | **Extensions see agents' orders.** The order's source (669) in extension scripts (`order.source`) and the add-on API (`OrderSummary::$source`), and an `on agent_checkout_completed` event |
| 📝 | 694 | P3 | **Extensions add capabilities.** An extension declares a UCP extension of its own (`capability "dev.example.gift_wrap" { extends: checkout, schema: "…" } fn …`), listed in the profile and run in the sandbox like a route, so a shop's add-ons are agents' too |

## Phase 9: the owner sees it

| | # | P | Item |
| --- | --- | --- | --- |
| ⬜ | 695 | P3 | **Shop › Integrations shows the agents' work.** Sessions in the last 30 days by platform: started, handed to the buyer, completed, abandoned; the orders they became and their total; the last errors agents met (`messages` with error codes), so an owner sees what stops agents buying |
| ⬜ | 696 | P3 | **Reports.** Sales by source (the shop's checkout, agents by platform) in the sales report and its export |

## Order of work

1. Phase 1b (666–671): 666 first, as it loses sessions today.
2. Phase 2 (672–675): signing, which phases 4 to 6 need.
3. Phase 5 (683–684): the agent hears about the order. Cheap once 672 exists, and agents ask for it first.
4. Phase 3 (676–678).
5. Phase 4 (679–682), after its design.
6. Phase 6 (685–690), after its design. It needs 672, 673 and 683.
7. Phases 8 and 9 alongside, and phase 7 only if a platform needs ACP's checkout.

## Tests each phase adds

- Integration: every operation's answer validated against UCP's JSON Schemas (675), plus the failure codes.
- End to end: an agent over REST and a browser for whatever the buyer does. For phase 4, a buyer linking their
  account. For phase 6, a test card through Stripe's test mode, with the 3-D Secure escalation.
- An MCP client (the official SDK, as `tests/e2e/assistant.spec.ts` uses) for the MCP binding.
