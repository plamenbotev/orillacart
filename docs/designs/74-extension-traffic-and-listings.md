# 74 · Extensions' routes under traffic, and listing products for shoppers

Status: built (2026-11-13). Extension API 29. PLAN items 653–657.

An extension's landing page that pages and filters products calls a public route on every tick. Item 652 counted
those routes per visitor address and extension, 240 in 10 minutes: a phone network or an office puts hundreds of
shoppers behind one address, and one shopper browsing quickly reaches it alone. The shop's own storefront reads
(`/product-cards`, reviews, the cart) are not counted at all, nor are WordPress's. A per-address count in PHP does
little against a flood either: WordPress has started and queried before anything is counted, and an attacker
changes addresses. A flood is the site's firewall's or CDN's to stop. And an extension had no way to list
products for shoppers: `shop.products.search` finds drafts too, needs `products: read`, and has no filters,
pages or counts.

## Decisions

**Counted only where a firewall cannot tell abuse from use.** Reads (GET) and signed-in callers are not counted,
as the shop's own are not: a customer, a manager, an admin screen's `window.orillacart.api()`, an app with a key or
token. A `public` route that changes something is, as the shop's own forms are: a code tried one after another, and
the extension's hourly email and HTTP allowance (50 and 500) spent by one visitor, are use to a firewall. What a
route runs is bounded by the run's budget (2 seconds, 50 queries), which is what leaves floods to the firewall.

**Generous by default, and the route may say.** All of an extension's public writes take 1 200 per caller in 10
minutes, two a second, as many as the shop takes of a payment provider's webhooks, so a service posting to a route
is not turned away. A route sets `limit: 20` (counted for it alone: a route that checks a code) or `limit: none`. A
caller is its account when signed in, else its address (an IPv6 /64). The shared ceiling of all extensions, and
the counts of account and manager routes (652), are gone. A site's `orillacart_rate_limits` changes
`extension_public`, and `extension_route` for every route's own limit, but cannot switch them off. Public writes
still pass the site's human check; reads and signed-in callers no longer do.

**A read the route keeps.** `cache: seconds` (up to 3 600) on a public GET route answers a visitor from what an
earlier one got, by path, parameters and query (in any order), language, currency, the extension's build and
settings, and the search index's generation, so a product changed is answered anew. Guests only (a signed-in
caller's answer may be theirs: group prices, their account), and only answers that went well. Kept in the object
cache, or transients without one. Guests are priced at the shop's address, so no visitor's address is in the key.
`X-OrillaCart-Cache: hit|miss` says which.

**Failures a visitor cannot use to stop an extension.** Ten failures in a row stop an extension. A visitor who
finds the input a public route breaks on could send it ten times and switch the extension off for everyone. Now
an anonymous caller's (a visitor's or a customer's) failures count once an hour each: real traffic meeting a real
failure still stops it after ten callers in a row; staff, jobs, WP-Cron and the command line count in full.

**The shop's listing for extensions.** `shop.catalog.list(query)`, and `Registry::catalog()` for PHP add-ons (API
29), are the shop page's own listing (`ShopListing`): the search index, filters with counts, price range, sorts,
pages and the shop's cards, with the choices from the extension. Only what a shopper may find, in their language and
currency, priced as shown to them; so no permission. The guide recommends a server-rendered `page` (not a REST
request, kept by the page cache) and, for instant filtering, a route with `cache`.

## Not done

- Cache-Control for a CDN on cached route answers: the shop's own invalidation would not reach it.
- A route that counts a visitor by session rather than address: a cookie is the caller's to drop.
