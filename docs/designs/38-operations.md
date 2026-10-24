# 38 · Operations

Status: built (2026-10-21), PLAN item 95.

## Commands

- `wp orillacart doctor [--fix]`: the shop's checks as Site Health runs them (`ShopHealth`, shared by Site
  Health, the Status screen and this), with a new one for the search index; an error on a critical finding,
  for deploy scripts. `--fix` runs the changes to the tables that have not run, clears what the shop compiled,
  makes deleted shop pages again and rebuilds the search index, then checks.
- `wp orillacart export orders|products|subscribers [--format] [--file] [--<filter>=…]`: as the Export screens,
  streamed to a file or the standard output; orders take the Orders screen's filters.
- `wp orillacart anonymise <email> | --order=<number>`: a person erased as a privacy request does, or the
  customer from one order; asks first unless `--yes`.
- `wp orillacart settings export|import`, `wp orillacart sample-data add|remove`, `wp orillacart routes`.

## Status, dashboard, admin bar

- Shop → Status: the checks, each with what to do and where; what the shop, WordPress and the server are; a
  report to copy for support (no keys, passwords or customers). `GET /status`.
- The dashboard's Shop box: today's and the last seven days' net sales (as Reports counts them), the orders
  to ship and those that need attention, the items low in stock.
- The admin bar: how many orders wait, with links to those to ship and those that need attention; counted
  once a minute at most (a transient).

## Change log (src/Audit)

Each change a shop manager makes through the shop's API, once it succeeded: who, when, the route and what was
sent, with keys named like secrets, passwords, tokens and API keys blanked out (`change_log`). Reads, failures
and visitors' requests are not kept, nor routes that only work something out (`changes: false`: the order
quote, an email preview), nor more than 8 KB of what was sent. What was sent may be a customer's address or
email: once a privacy request erases them, or an order is anonymised, the records made on their order or
account, and those that mention their email, keep only who changed what, and when. A middleware writes it
after the action, building the log only for a change, and never fails the change for it. Shop → Status lists it by area;
`GET /change-log`. A year is kept.

## API keys and webhooks (src/Integrations)

- Keys: `oc_` and 40 hex characters, shown once; their SHA-256 is kept (`api_keys`), with the first characters,
  the shop manager it acts as, read only or read and change, and when it was last used (saved at most once a
  minute). `Authorization: Bearer <key>` or `X-OrillaCart-Key` on the shop's routes only: such a request acts
  as that user, needs no nonce, and its cookies are not asked about. A read-only key changes nothing; no key
  manages keys or webhooks; a key the shop has not is a 401. "The shop's routes" is the route WordPress serves
  (its `rest_route` parameter wins over the path, and the query string counts for nothing), checked again when
  it dispatches (127).
- Webhooks (`webhooks`, `webhook_deliveries`): a name, an https address (http only on this computer), topics.
  The shop's actions (`orillacart_order_placed`, …) become `webhook.deliver` jobs in their own lane, which POST
  `{id, topic, created_at, data}` (an order or product as the API shows it, when delivered) with
  `X-OrillaCart-Signature: t=<time>,v1=<HMAC-SHA256 of time.body>`. Not 2xx: tried again six times over a day;
  the latest fifty tries are kept. Add-ons add topics (`orillacart_webhook_topics`) and send their events
  (`orillacart_send_webhook`). Shop → Integrations manages both, sends a test and makes a new secret. A secret
  is kept sealed (as payment keys are) and shown once, when the webhook or a new secret is made; one the salts
  no longer open sends nothing until a new one is made (127).

## Settings file

What the settings screens save, as JSON (`GET`/`PUT /settings-archive`, Settings → Settings file, the
command): the shop's, tax, emails, invoices, customers, newsletter, reviews, storefront, export columns,
downloads and the search engine. Pages and folders stay with each site; secrets are never in the file, and an
import keeps the site's. Each section is read and saved back as the shop does: a value it would not keep as
written leaves the section as it was, named with why. Payment methods, carriers and tax rates are records,
made on their screens.

## REST documentation

`RouteDocumentation` reads the routes: methods, who may call them, nonce, rate limit, the action's docblock,
and the body or query fields from the DTO the action maps (rules in words), with the shapes of nested objects.
`bin/rest-docs` writes `docs/rest-api.md`; a test fails when it falls behind.

## Setup and sample products

- Shop → Setup: the shop's country, currency and units; the ways to pay without an account elsewhere; a first
  rate to the shop's country when it has no carriers; a product, an import or the sample products. The admin
  offers it until finished or put off ("Not now"). `GET`/`PUT /setup`.
- Sample products: eight, made through the shop's own API, each marked, so removing takes them with only what
  they brought (pictures, categories, brands, filters, a stockroom) that nothing of the shop's uses.
  `GET`/`POST`/`DELETE /sample-data`.
