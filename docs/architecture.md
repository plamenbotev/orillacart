# Architecture

OrillaCart is a WordPress plugin built as a modular application: one folder per part of the shop, each in the same
four layers, with WordPress kept at the edges. A fixed request pipeline, domain events and a versioned extension
API join the parts. Why each part is as it is lives in [the designs](designs/); what is being done, in
[PLAN.md](../PLAN.md).

## Modules

`src/` holds a module per part of the shop:

| Part | Modules |
| --- | --- |
| Selling | Catalog, Inventory, Cart, Checkout, Ordering, Payment, Shipping, Tax, Coupons, GiftCards, Returns, Invoicing |
| People | Customers, Reviews, Newsletter, Notifications |
| Running the shop | Reports, Exchange (import and export), Jobs, Audit, Search, Settings, Setup, Translations, Geography, Forms |
| Outward | Storefront (blocks and pages), Integrations (API keys, OAuth), Assistant (MCP for the shop's manager, [assistant.md](assistant.md)), Agents (UCP for shoppers' agents, [shoppers-agents.md](shoppers-agents.md)) |
| Extensibility | Extension (the public API), Scripting (the extension language), Packages (installed extensions) |
| Base | Shared (what every module builds on), Platform (start-up and wiring) |

## Layers

Each module has the same four folders. [deptrac.yaml](../deptrac.yaml) holds the rules, and CI runs Deptrac
without its cache:

```
        Http ───────────► Application ◄─────────── Infrastructure
                               │                      │  Doctrine, WordPress,
                               ▼                      │  Symfony, outside APIs
                            Domain ◄──────────────────┘
```

- **Domain**: entities and value objects (`Cart`, `AgentCheckout`), repository interfaces, domain events
  (`CartOrdered`, `CartClaimed`). Only Doctrine's mapping attributes and the shared domain types: no WordPress, no
  container, no HTTP.
- **Application**: the use cases (`CartService`, `CheckoutService`, `AgentCheckouts`) and their DTOs, and an
  interface for each thing they need from outside (`CheckoutPrefills`, `AgentEndpoints`, `RateLimiter`). They may
  call other modules' Application services; never WordPress.
- **Http**: an action class per route, declared by its attribute
  (`#[Route('/ucp/mcp', methods: ['POST'], capability: Route::PUBLIC, signed: true)]`). It turns the request into a
  call of the Application layer. No WordPress here either.
- **Infrastructure**: what implements those interfaces: Doctrine repositories, WordPress options and hooks, HTTP
  clients, event listeners. The only layer that calls WordPress.

A module reaches WordPress only through an interface its Application layer declared, so most of the shop is
tested without WordPress, with in-memory fakes (`tests/Fixtures`).

`Shared` is split the same way: `SharedDomain` (money, identifiers, the shared exceptions) and `SharedKernel`
(HTTP, security, routing). `Platform` is the one place that sees every layer. Type aliases that cross modules
(`@phpstan-import-type`) belong to the `TypeAliases` layer.

## Start-up and wiring

```
orillacart.php → Plugin::boot()
                   ├─ activation, deactivation and upgrade (migrations on the first request after an update)
                   └─ Kernel → Symfony container (compiled at the release build, cached in var/cache in development)
                                └─ WordPressIntegration::register()
                                     ├─ #[AsHook] classes        → add_action / add_filter
                                     ├─ #[Route] actions         → REST routes (RestRouteRegistrar)
                                     └─ #[AsEventListener]       → Symfony's event dispatcher
```

- **The container** is autowired from [config/services.php](../config/services.php). Only Application services
  are public. Each interface is aliased to its implementation there by name.
- **Attributes are the registration.** A new hook, route or listener is a class with its attribute; there is no
  list to keep.
- **Multisite**: each site has its own container (`SiteKernels`), its own tables, pages and roles; sharded
  networks work too (design 54).
- **The database** is Doctrine ORM over WordPress's connection. The schema changes only by
  [migrations](../migrations/). Personal and secret columns are `sealed_json` / `sealed_string`, encrypted.

## A request

Every route the plugin answers (REST, admin-post, the shop's pages) goes through one `HttpKernel`, a PSR-15
pipeline:

```
the WordPress adapter (REST, page) → HttpKernel
   ErrorMiddleware          exceptions as JSON errors; Retry-After
   AuthorizationMiddleware  the route's capability: public, signed, or a shop capability
   CsrfMiddleware           a nonce for writes authenticated by cookie
   tagged middleware        rate limits per guard, and the rest
   ActionDispatcher         → the #[Route] action → an Application service
```

The first three are constructor parameters, not configuration, so no route is served without them.
`RouteSecurityGateTest` lists every public and signed route, so a new one is a decision someone reads. The same
attributes give the [REST reference](rest-api.md) (`bin/rest-docs`) and the assistant's tools (`#[Tool]`).

## How modules work together

- **Calls**: an Application service calls another module's (`AgentCheckouts` uses `CartService` and
  `CheckoutService`).
- **Events**: a module tells what happened and others react, without it knowing them. `CartService` dispatches
  `CartOrdered` and `CartClaimed`; the Agents module's listener completes or moves its sessions. The Cart module
  knows nothing of agents.
- **Interfaces the other side implements**: Checkout declares `CheckoutPrefills`; Agents implements it.

## Work in the background

`Jobs` is the shop's own queue, worked by WP-Cron or by WP-CLI workers (`wp orillacart jobs work`): the search
index, mail, retention, and timed tasks such as forgetting agents' old sessions. See [workers.md](workers.md).

## Extensibility

1. **The extension API** (`src/Extension`) is the one stable surface, versioned by `Version::CURRENT`. Other
   plugins register providers there through `Registry`: payment, shipping, tax, the catalogue, and more. The
   closed Platform plugin (`../orillacart-platform`) may use only this API and WordPress, as its own Deptrac rules
   hold. See [extensions.md](extensions.md).
2. **Extension scripts** (`src/Scripting`): a sandboxed language compiled to PHP, whose output is checked against
   an allowlist (`GeneratedCodeGate`). Scripts reach the shop only through host functions, each behind a
   permission the extension declares: routes, admin pages, blocks, templates, tests, and a debugger. See
   [extension-scripts.md](extension-scripts.md) and [the guide](extension-scripts-guide.md).
3. **Packages** (`src/Packages`): installing, versioning, uploading and exporting extensions as zips; their routes
   (limits, cache) and files. Their code in the browser runs in a sandboxed frame (design 73).

## The browser's side

- `assets/admin/<screen>/`: a React and TypeScript app per admin screen, on WordPress's components and DataViews;
  what screens share is in `assets/admin/shared`.
- `assets/storefront`: the shop's own script. The shop's pages are blocks (`blocks/`), whose PHP in Storefront
  renders Twig templates (`templates/`).

## Tests and checks

- **Unit** (`tests/Unit`): no WordPress, fakes in `tests/Fixtures`.
- **Integration** (`tests/Integration`): real WordPress and MySQL in wp-env (`npm run test:integration`).
  `self::kernel()` builds a fresh container at each call, and only Application services can be fetched from it.
- **End to end** (`tests/e2e`): Playwright on the same site, after the integration tests.
- **Static**: PHPStan, Deptrac, php-cs-fixer, and ESLint (`npm run lint:js`).
