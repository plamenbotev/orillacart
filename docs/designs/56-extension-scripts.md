# 56. Extension scripts

Status: built (2026-11-07). PLAN items 317–330, 333–338 done; 331 and 332 (versions, run log, tool exposure of scripted routes, override diffs; the fields' admin sections, views, columns and facets) are later.

Each tenant of a SaaS, or the owner of one shop, extends and edits their own shop without a PHP plugin: new
payment and shipping providers, new endpoints and pages, new kinds of records and new fields on the shop's own,
edited and new templates. Written by a person in the admin or by an agent over MCP, in a language the shop runs
in a sandbox it controls, with budgets, permissions and a kill switch. An extension lives in the site's database,
not on disk, so it follows the tenant through dumps, moves and shards (design 54).

## Decisions

**One language, declarations and code together.** An extension is a few files in one language, call it
OrillaScript (`.ocs`). The same files declare what the extension adds (settings, providers, routes, objects, fields,
templates it uses, events it hears) and hold the functions that do it. There is no separate manifest format, so
there is one parser, one static check, one editor and one thing an agent learns.

**A language of its own, compiled to PHP by the plugin.** Not PHP in any subset (its semantics leak: references,
magic methods, `(string)` casts, resource limits), not Lua or JavaScript (no sound interpreter in PHP), not Twig for
logic (no return values, no errors as values). A small imperative language with Twig's expression grammar, so a
template and a script read the same way (`order.total|money`, `x ?? y`, `a..b`, `name is empty`), plus statements:
`let`, assignment, `if`/`elif`/`else`, `for … in`, bounded `while`, `fn`, `return`, `break`, `continue`,
`try`/`catch`, `fail`. Values are null, booleans, numbers, money (decimal, never float), strings, lists, maps, and
the host's records, which are read-only. There are no classes, no references, no globals except the host's
namespaces. The compiler turns a package into one PHP class per tenant and checksum, whose methods are the
handlers: every operation goes through the runtime's helpers (`Ops::add`, `Ops::get`, `$c->call('http.post', …)`),
every loop iteration and call counts a step, and nothing in the generated code names a PHP class, function or
global of its own, which the compiler and a test over the generated source guarantee. The class is a cache: written
under the shop's cache folder per site, build and checksum, loaded by `require` when present, compiled again when
missing, and evaluated in memory when the folder is not writable. Generated PHP runs at PHP's speed with OPcache,
which an interpreter in PHP could not, and the shop's tooling (phpstan on a sample, Xdebug) reads it.

**Everything already in the extension API comes for free.** Design 56 adds no second way to be a payment gateway.
A scripted provider is an adapter class that implements the existing `OrillaCart\Extension` contracts and calls the
script's functions: `ScriptedPaymentGateway implements PaymentGateway, HandlesReturns, HandlesNotifications,
RefundsPayments, ChecksPayments`, and so on for `ShippingMethod`, `CartRule`, `OrderFee`, `PriceAdjuster`,
`CheckoutField`, `OrderAction`, `OrderColumn`, `OrderFilter`, `HomeContributor`, `AssistantTool`, `ProductBlock`,
`MailingList`. They register on the same `Registry`, under the extension's id, with the same validation, caching,
settings sealing and failure handling the PHP add-ons get. What the shop learns about add-ons (`docs/extensions.md`)
stays true for scripts.

**Three things are new in the core:** endpoints and pages a script answers, custom objects and fields, and
templates from the database. Each is a bounded surface described below.

**Safety is by construction, then by budget, then by permission.** The language cannot name a PHP class, open a
file, run a query or call a URL: it can only call host functions, and every host function checks the extension's
permissions. A run has a budget of steps, time, memory and outbound calls. An extension declares what it needs
(orders: read, customers: write, http: `api.payfast.example`) and a manager grants it when enabling it. An
extension that keeps failing is switched off by the shop and the owner is told.

## The language

```
extension "payfast" {
  name: "PayFast"
  version: "1.2.0"
  needs: { orders: read, http: ["api.payfast.example"] }
}

settings {
  merchant_id: text { label: "Merchant id", required: true }
  secret: secret { label: "API secret", required: true }
}

payment_gateway "payfast" {
  name: "Pay with PayFast"

  fn start(session, settings) {
    let answer = http.post("https://api.payfast.example/sessions", {
      json: { amount: session.amount, currency: session.currency, return_url: session.return_url },
      headers: { Authorization: "Bearer " ~ settings.secret },
    })
    if answer.status != 200 {
      fail unavailable("PayFast answered " ~ answer.status)
    }
    return payment.redirect(answer.json.url, answer.json.id)
  }

  fn notified(notification, settings) {
    let event = notification.json
    if event.type == "paid" { return payment.completed(event.reference, event.id, money(event.amount, event.currency)) }
    return null
  }
}

object "ticket" {
  fields: { subject: text { longest: 200 }, body: text, order: order, state: choice { of: ["open", "closed"] } }
  index: [state, order]
}

route GET "/tickets" { who: account } fn(request) {
  let mine = objects.ticket.query({ where: { "order.customer": request.user.id }, order_by: "-created_at", limit: 50 })
  return respond.json(mine)
}

page "/support" { template: "support.html.twig" } fn(request) {
  return { tickets: objects.ticket.query({ where: { state: "open" }, limit: 20 }) }
}

on order_placed fn(event) {
  objects.ticket.create({ subject: "Welcome " ~ event.order.email, body: "", order: event.order.number, state: "open" })
}

template_data "storefront/product.html.twig" fn(context) {
  return { stock_note: context.product.ext.payfast.lead_time ?? "" }
}

every "daily" fn() { ... }
```

Numbers are integers or decimals; `money(amount, currency)` makes a money value, and arithmetic between money and
numbers rounds as the shop does (`Money`). Strings concatenate with `~`. A map's keys are strings. Host records
(`session`, `event.order`, `request`) are read-only maps with the REST API's field names, the same names templates
use (`TwigRenderer` normalises to them already). Errors are values: `fail unavailable(...)` becomes
`ProviderUnavailable`, `fail refused(...)` a refusal the caller shows, any other failure a logged problem. `try`
catches what `http` and `objects` raise. `while` and `for` count against the step budget; a run that exceeds it is
stopped and logged, never retried in place.

### Host namespaces

Every function is a PHP class method with a signature the static check knows, grouped by what it needs:

| Namespace | What | Needs |
| --- | --- | --- |
| `payment`, `shipping`, `cart`, `fee`, `price` | the factories of the extension API's outcomes (`payment.redirect`, `shipping.rate`, `cart.refuse`) | the provider declared |
| `shop.orders`, `shop.products`, `shop.customers`, `shop.stock`, `shop.settings` | read the shop; `find`, `query` with the same filters the REST list routes take, paged, at most 200 rows a call | `orders: read` … |
| `shop.orders.note`, `shop.orders.mark`, `shop.stock.change`, `shop.products.mark` | write what the extension API already lets add-ons write | `orders: write` … |
| `objects.<name>` | the extension's own records: `create`, `find`, `query`, `update`, `delete` | its own, always |
| `fields` | read and write the extension's fields on products, orders and customers | the field declared |
| `http` | `get`, `post`, `put`, `delete` to declared hosts only; JSON or form bodies; 5 s timeout, 1 MB answers | `http: [hosts]` |
| `respond` | `json`, `html(template, data)`, `redirect`, `error(status, message)` from a route | a route |
| `jobs` | `push(name, payload, at?)` to the extension's own handlers, run in the `extensions` lane | always |
| `log` | `info`, `warning`, `error` → debug.log and Shop → Status → Problems, under the extension's name | always |
| `text` | `__`, `_n`, `slug`, `format` | always |
| `time` | `now`, `today`, `parse`, `add`, `format` in the shop's timezone | always |

Nothing else: no `eval`, no reflection, no file, no raw SQL, no WordPress function. New host functions are a PHP
change, versioned with `Extension\Version` as the add-on API is.

## Endpoints and pages

A `route` declares a method, a path under the extension's own prefix (`/orillacart/v1/x/<extension>/…`), who may
call it (`public`, `account`, `manager`) and a handler. The shop makes a real `RouteDefinition` from it, so the
route goes through the same HttpKernel pipeline as the plugin's own: authorization, CSRF nonce for browsers, API
keys and OAuth for apps, the `GuardMiddleware` rate limits (an extension's routes share one guard with a default of
120 calls per 10 minutes per caller, set in the declaration), and `RequestMapper` validation of the declared input
shape. A manager route is also a tool of the assistant, named `x_<extension>_<route>`, with the risk class declared
(`risk: money`), so agents reach it with the same approvals as the plugin's own tools.

A `page` is a storefront URL under `/<shop page>/x/<extension>/<path>` or a block (`orillacart/extension-page`) a
page's editor places; its handler returns the data its template gets. Pages render through the storefront Twig
with the extension's template, in the sandbox (below), inside the theme as the shop's own pages are. A `slot`
declaration puts a template into one of the storefront's existing slots (`product_after_details`, …) without a page.

## Objects and fields

A custom object is declared with typed fields (`text`, `number`, `money`, `boolean`, `date`, `datetime`, `choice`,
`list`, `map`, and references: `product`, `order`, `customer`, another object). Objects of every extension of a site
live in two tables: `extension_objects` (id, extension, type, data JSON, created_at, updated_at, and the referenced
product, order and customer ids as columns, so a deleted order or an erased customer cascades or nulls as the
`#[References]` of the shop do) and `extension_object_values` (object id, field, value as string, number and date)
for the fields the declaration indexes. `query` takes `where` on indexed fields and references, `order_by`, `limit`
and a cursor; a filter on a field that is not indexed is refused by the static check, not slow at runtime.

A field declaration (`fields on product { lead_time: number }`) adds to the shop's own records. The values live in
`extension_object_values` keyed by the kind and id, shown and edited in a generic "Extensions" section of the
product, order and customer screens (one block per extension, built from the declaration), returned by the REST
views under `extensions.<id>`, reachable in templates as `product.ext.<id>.lead_time`, exported and imported by the
existing column contracts, and copied into the search index as a facet when the declaration says `filter: true`.
Personal data: an object with a `customer` reference or a field on a customer is included in exports and erasure
through the `PersonalDataSource` contract, automatically.

Objects and fields are the only schema an extension has; it cannot make tables. An extension's data is deleted
when the extension is uninstalled, after the manager says so, and exported with it before.

## Templates

Three kinds, all stored in the site's database and all rendered in Twig's sandbox:

1. **The extension's own** (`templates/support.html.twig` in the package), used by its pages, slots and blocks.
2. **Overrides of the shop's templates.** The storefront loader gets a database loader in front of the theme and
   the plugin, as `EditedTemplateLoader` is for emails: `templates/storefront/product.html.twig` in an extension
   replaces the shop's. Twig's `SourcePolicyInterface` sandboxes only these sources, so the plugin's own templates
   keep their freedom and an override cannot call a method or reach an object. The policy is the email one, widened
   with the storefront's functions and the `slot` names; `strict_variables` holds, and a template that does not
   compile is refused at save, as edited emails are.
3. **Data for a template.** `template_data "<template>" fn(context)` lets a script add to what any template sees,
   under `ext.<id>`, so an override can show what the script computed, and a shop template the extension does not
   override can still get a value through a slot or a block.

The admin's template editor shows the plugin's original beside the override with a diff, and an override marked
stale when a release changes the original, as design 53's email templates do.

## Storage, installing and the cache

An extension is a row in `extensions` (id, name, version, state, permissions granted, budgets, failure count, the
checksum of its files) with its files in `extension_files` (path, content, checksum), both per site, so a tenant's
extensions travel with its database. Installing means putting the files there, from the editor, an uploaded zip or
an agent's tool; enabling runs the static check and grants the permissions; disabling keeps everything and stops
it; uninstalling removes the rows, the compiled class and, after the manager says so, the extension's objects and
field values (exported first). The admin does all four, and so do the MCP tools.

The compiled PHP is never stored in the database: it is derived from the files and the checksum and lives in
`CacheFolders` (`scripts/<build>/<site>/<extension>-<checksum>.php`). A new save compiles anew; a new plugin build
clears the folder as it clears Twig's; nothing has to be invalidated by hand. On a network each site compiles its
own, under its own id, so two tenants with the same extension id never share a class.

Compiling to PHP makes the compiler a security boundary: an escaping bug would let a script's string or name
become PHP that runs with the plugin's powers. The sandbox therefore does not rest on the compiler alone.
`GeneratedCodeGate` reads every generated source with PHP's own tokenizer and refuses it unless each token is one
the compiler is known to make: one class extending `CompiledScript`, methods `fn_*`/`m_*`, variables `$v_*`,
single-quoted strings, static calls on `Ops`, calls on `$this->c`, a short list of keywords; no other class,
function or constant, no `new`, `eval`, `include`, backtick, interpolated string, variable variable, comment or
inline HTML. The gate runs after compiling (a refusal is a finding and no source is kept) and again in
`CompiledScriptStore` before a cache file is included or a source evaluated in memory. Cache files also carry the
site's signature (`Signatures`, keyed with the site's salt and id) of their source on the last line; a file that is
unsigned, edited after signing, signed by another site or outside the grammar is deleted and the class compiled
again. The compiler quotes strings itself (single quotes, only `\` and `'` escaped) because `var_export` writes a
NUL byte as a double-quoted `"\0"`, which the gate found and refused. `GeneratedCodeGateTest` feeds the compiler
hostile strings, every byte included, in every place a string reaches the source, and checks the gate passes and
the value comes back unchanged.

Each site's classes live in their own namespace, `OrillaCart\Generated\Scripts\Site<id>`, and under their own
cache keys (`s<id>-<scope>-<checksum>`), so two sites of a network with the same extension never share a file or a
class (before, they shared both and each site's load deleted the other's file for carrying the wrong signature).
The gate is told the site it loads for and refuses a class of any other site before the signature is even
checked. A PHP namespace isolates nothing by itself, since any code may name any class; the isolation is the gate
and `Ops`. The namespace is a label that makes traces and OPcache listings read by tenant, and one more cheap
check against a signing key shared across sites by misconfiguration. `CompiledScript::run` checks the number of
arguments against the method's before calling, so a function called with too few or too many is a script failure
("hello expects 1 argument, 0 given") and not a PHP `ArgumentCountError` the error middleware logs as a 500.

## Where it runs

Enabling parses every file, runs the static check (syntax, unknown functions, wrong arities, undeclared
permissions, filters on unindexed fields, templates that do not compile, required handlers of each provider present)
and compiles the package. The compiled class's manifest holds the declarations as data, from which the site's
`Registry` registrations and route table are made. The site's kernel loads enabled extensions on
`orillacart_extensions`, as it fires for PHP add-ons: PHP add-ons keep working unchanged beside them, and on a
network each site has its own set, so design 54's sharding needs nothing more.

Handlers run inside the request or job that needs them, through the interpreter, with these budgets a manager can
tighten and not loosen beyond the shop's ceiling: 100 000 steps, 2 s of CPU, 16 MB of strings and lists, 5 HTTP
calls, 20 object queries. Event handlers (`on order_placed`) run in a job in the `extensions` lane, never in the
request that placed the order; providers, routes, fees, rules and template data run inline because their answer is
needed, with the same budgets. Ten failures in a row switch the extension to "stopped", keep its routes answering
503 with a plain message, and put a line on Home; a manager starts it again after reading the problem.

## The admin and the agent

**Extensions screen** (Settings › Extensions): the list with state, version and last problem; an editor with the
package's files, syntax errors and the static check's findings as you type, a "Check" button and a "Run" console
that calls one function with sample input against a read-only view of the shop; settings per extension from its
`settings` block; permissions asked for and granted; versions kept on each save with rollback; import and export of
a package as a zip with a checksum; the log of its runs with budgets used.

**The editor** (PLAN 335, 336) is CodeMirror with a stream grammar for the language and Twig and HTML modes for
templates. The compiler on the server stays the only parser: as the manager types, the buffer and the package's
other files go to `POST /script/check`, a stateless route that answers each finding with its file, line and
column, and the editor underlines them; completion and hover come from `GET /script-reference`, which lists every
host function with arity, summary and permission, the filters, tests, keywords and slots, plus the names the
buffer declares (`let`, parameters, top-level functions, the extension's object kinds for `objects.<kind>.…`).
Nothing of the language is re-implemented in TypeScript beyond a tokenizer for colour. The same two routes are
what an editor outside the admin uses (PLAN 337, the VS Code extension with a language server that proxies them).

**MCP tools**, in a new area "extensions" a key may be given: `extension_list`, `extension_read`,
`extension_write_file`, `extension_check`, `extension_run` (the console), `extension_enable` (risk: opening, since it
grants permissions), `extension_disable`, `extension_export`. An agent writes an extension the way a person does,
with the same checks and the same approvals; the language reference and the host function catalogue are an MCP
resource (`orillacart://extensions/reference`) so the agent reads them from the shop it is working on, in the
version it runs.

**Docs:** `docs/extension-scripts.md`, the language reference and the catalogue, generated from the host functions'
signatures by a `bin/script-docs` as the REST docs are, with a test that they are current; and
`docs/extension-scripts-guide.md`, the hand-written guide (statements, expressions, values, filters, failures, what
each handler receives and answers, routes, templates, objects, background work), whose every example a test compiles
against the site's host functions (PLAN 334).

## What this is not

Not a replacement for PHP add-ons: a provider that needs a vendor SDK, a database table of its own, a WordPress
hook the shop does not expose, or more than the budgets allow is still a plugin. Not a theme: layout and styling
stay the block theme's; an extension overrides a template's markup only where the theme did not. Not multi-tenant
code sharing in phase one: an extension belongs to one site; a network-wide catalogue of extensions a super admin
offers to tenants is a later design.

## Built as it stands

Everything above except: versions with rollback and the run log on the Extensions screen; scripted manager routes
as assistant tools; an override's diff and stale mark; the fields' generic admin sections, REST views, export
columns and search facets (PLAN 331, 332). Two things the build decided differently from the plan: a route answers
JSON only (a rendered template travels as `{ html }`), since WordPress's REST server answers JSON; and a route
cannot waive the REST nonce, since no unsafe route of the shop does. The proof ran on the `oc-ms` network: an
extension installed on site 3 kept its package and records in the tenant database `wp_t3`, answered its routes
there, rendered its page block, and did not exist on site 2. The hard gate on generated code and the signed cache
files (PLAN 333) came after the build, from a review of the compile-to-PHP decision.

## Open points

- The name and file extension of the language (OrillaScript, `.ocs`) are placeholders.
- Whether a super admin on a network may forbid extensions, or some permissions (`http`, `customers: write`), per
  site or network-wide. The design assumes yes, a network setting, since the host carries the risk.
- Whether storefront overrides are allowed from the start or only the extension's own templates and slots. The
  design builds the sandboxed loader first and makes overrides a permission (`templates: override`).

## Phases

1. **The language** (317–319): lexer, parser, the compiler to PHP with the runtime helpers and budgets, values
   (money), errors as values; the host function model with signatures, permissions and the static check; the
   compiled-class cache; `wp orillacart script check|run`; a conformance suite of scripts with expected outputs.
2. **Packages and the admin** (320–322): the `extensions` tables, enabling with the static check, the compiled
   registrations on `orillacart_extensions`, settings with sealing, the circuit breaker and Home line, the
   Extensions screen with editor, console, versions, import and export, the MCP tools and the reference resource.
3. **Providers** (323–324): the adapters for the extension API's contracts, payment and shipping first with the
   test fixtures' scenarios, then the rest; the `http` namespace with host allow-lists.
4. **Routes, pages, slots, templates** (325–327): routes through HttpKernel with guards and assistant tools, pages
   and blocks, the sandboxed database loader with per-source policy, overrides as a permission, template data.
5. **Objects and fields** (328–329): the two tables, references with cascades, indexed queries, the generic admin
   sections, REST views, templates, exports and imports, personal data, search facets.
6. **Events, jobs, schedules, docs** (330): `on`, `every`, `jobs.push` in the `extensions` lane, the generated
   reference and `docs/extension-scripts.md`, and an end-to-end proof: a scripted gateway and carrier, a ticket
   object with a page and an override, written through MCP by an agent, enabled by a manager, run on `oc-ms`
   per tenant.
