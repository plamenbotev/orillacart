# 49 · An AI store manager over MCP

Status: designed 2026-10-26, PLAN items 178–190; 178–187 and 189 built. The user asked for an LLM store manager that works the moment
the plugin is installed. It is bundled, not a separate plugin, and it covers setting up the shop and all
administration. On 2026-10-26 they added that the tooling must be extendable.

## Before

The shop already has most of what an assistant needs:
- **Routes that describe themselves.** 222 routes are for shop managers. Each states who may call it, and its
  action's docblock says what it does. The DTO it maps the request to gives every field, its type and its rules.
  `RouteDocumentation` reads all of this for docs/rest-api.md.
- **API keys.** Shop → Integrations makes a key (`Authorization: Bearer oc_…`) that acts as the manager who made
  it. A read-only key changes nothing, and no key touches keys or webhooks.
- **The change log** records every change a manager's request made.
- **The setup** saves answers per step, plans, and applies the plan in one go with its fingerprint.
- **The extension API** (version 14) lets add-ons register gateways, carriers, columns, filters and more.

What is missing:
- **A way for an AI client to call the shop.** Clients speak MCP (Model Context Protocol), not the REST API.
- **The parts WordPress owns.** A product's title, text, images and categories are a post, its terms and media.
  The shop's routes only edit the commerce side, so creating a product needs WordPress's own API.
- **Asking before.** Nothing stops a model from refunding an order or deleting a coupon on a misunderstanding.

## How it works

**One endpoint, in the plugin.** `POST /wp-json/orillacart/v1/mcp` speaks MCP's Streamable HTTP transport.
Answers are single JSON responses; nothing needs a long-lived stream. It speaks the stateless revision
2026-07-28 (`server/discover`, the protocol version and client capabilities in each request's `_meta`, the
`Mcp-Method` and `Mcp-Name` headers) and the earlier 2025-11-25 and 2025-06-18 with their `initialize`
handshake, which most clients still send. `GET` answers 405, as the transport allows.

**A key connects.** The owner makes a key in Shop → Integrations → AI assistant and pastes the endpoint and key
into their client. The admin shows the lines to paste for Claude Code, Claude Desktop, Cursor and VS Code. A
request without a valid key gets 401 with `WWW-Authenticate: Bearer`, as the spec asks. A read-only key may call
the endpoint and the routes that only work something out (a quote, the setup's plan), since they change
nothing; every tool's own route is still held to the key's scope. The endpoint checks
`Origin` when a browser sends one, against DNS rebinding.

**Tools come from the routes.** Every shop-manager route becomes a tool, read the way the REST docs are read:
- **Name** from the action: `ChangeOrderStatusAction` is `change_order_status`. Names use only letters, digits and
  underscores, which every client accepts.
- **Description** from the action's docblock, with who may call it and what the tool changes.
- **Input schema** (JSON Schema 2020-12) from the path's placeholders and the DTO's constructor: types and list
  types from the docblock, and the validator's rules as `minLength`, `maxLength`, `minimum`, `enum`, `pattern`
  and `format`. Nested DTOs become `$defs`.
- **Annotations**: `readOnlyHint` for GET and for routes marked `changes: false`, `destructiveHint` for DELETE
  and for what the risk classes below name, `idempotentHint` for PUT.

One reader (`RouteInputs`) serves both the REST docs and the tools, so they cannot drift apart. The tools are
worked out once, when the container is compiled, not on each request. A `#[Tool]` attribute on an
action can rename it, describe it for a model, set its risk, or leave it out with a reason (the storefront's
routes, the payment providers' webhooks, keys and webhooks). `#[Tool]` can also name one route or method
of an action that has several. A test fails when a manager route is neither a tool nor left out with a reason,
when two tools share a name, when a tool has no description, or when an action reads a body no schema
describes.

Two kinds of input need more than a DTO:
- **A file.** A route that takes an upload (a products import, a download file) takes `file_name` and either
  `file_base64` or `file_url`. The shop fetches an address with WordPress's safe HTTP, which refuses the site's
  own network, up to 100 MB, and hands the route a real upload.
- **A whole body.** The settings import reads its body whole. `#[Tool(wholeBody: …)]` makes it one argument,
  `settings_file`, as the export gives it.

**Running a tool runs the route.** The endpoint builds the route's request in the same PHP process and hands it to
the HTTP kernel. That way the call passes the capability check, the key's scope, validation and the change log
exactly as the admin's own request would. Nothing is checked twice or in a second way. The change log says the
change came from the assistant, with the key's name; a plain API call says which key made it. A success returns the route's JSON as `structuredContent`
and as text. A refusal returns `isError` with the message and each field's violation, so the model can correct
itself.

**Tools for what WordPress owns.** These are written by hand, on the services the editor uses:
- **Products**: find and list (search, category, status, stock), create with title, text, excerpt, status,
  categories and images, update, and move to the trash. The commerce side stays on the route tools.
- **Categories**: list, create, update with order, layout and thumbnail, and delete.
- **Images** from a web address (fetched with WordPress's safe HTTP, images only, size-limited) or sent as
  base64, into the media library.
- **The shop's pages**: read and replace the text of the pages the shop uses, such as the legal pages, so the
  owner can ask for them to be checked and edited.

**The setup as tools.** One tool per step, `setup_answer_business`, `setup_answer_tax` and so on, with the step's
answers DTO as its schema. Add-ons' steps get theirs too. The setup's other routes are tools already:
`plan_setup` shows what would change, keep or replace, in the plan's own words. `apply_setup` takes the plan's
fingerprint, so what is applied is what was shown, and `show_readiness` lists what still stops the shop. A prompt, "Set up my shop", walks the model
through it: ask the owner about the business, save each answer, show the plan, apply only when they agree.

**Asking before.** Some tools carry a risk class:
- **Money**: refunds, marking an order paid, gift card balances, store credit, a payment's capture.
- **Deleting**: anything a DELETE route does.
- **Customers hear of it**: emails sent to customers, a return approved or refused.
- **The shop opens or closes**: opening, "Opening soon", applying the setup.

Each key says which classes need the owner's yes; by default all four do. A login, or an application password,
has the defaults. A client that can ask its user (MCP
elicitation) is asked through the protocol: the tool answers "input required" with what it is about to do,
in words ("Refund €40.00 of order 1043 to PayPal"), and runs only when the client retries with the owner's yes.
In 2026-07-28 this is a plain retry of the same request. The question carries a state the shop signed with the
site's secret: who asked, with which key, and a digest of the tool and its arguments. It lasts 15 minutes and
is good once, so a yes cannot be replayed (a refund would run twice) or used for other arguments. A client that cannot ask gets a refusal that names the
setting. The owner can untick a class for a key they trust. Clients of the earlier revisions cannot be asked statelessly,
so they are refused for the classes their key asks about.

**Checking the connection.** Some hosts drop the `Authorization` header before PHP sees it. The admin's check
sends a made-up key without cookies: "a key the shop has not" means the header arrives, "log in" means it was
dropped, and the admin says to send `X-OrillaCart-Key` instead.

**Many tools, or a few.** About 250 tools is fine for clients that search their tools, such as Claude, but too
many for some others. Each key chooses:
- **All tools** (default): every chosen tool is listed, in a fixed order so clients can cache the list.
- **Find on demand**: three tools, `find_tools` (by words or area), `describe_tool` and `run_tool`, over the same
  catalogue.

Each key can also leave out whole areas (reports only, or orders and customers only).

**Resources and prompts.** A resource, `orillacart://shop`, gives the shop at a glance: name and address,
country, currency, whether visitors can order, and what readiness still stops. Three prompts start the common
jobs and name the tools to use: "Set up my shop" (asks step by step, shows the plan, applies only on a yes),
"Review today's orders" and "What is running low"; neither of the last two changes anything unasked.

## Extending it

**Add-ons add tools** (extension API 15). An add-on registers a tool on the registry:

```php
add_action('orillacart_extensions', static function (OrillaCart\Extension\Registry $registry): void {
    $registry->assistantTool(new MyCarrierBookPickupTool());
});
```

`OrillaCart\Extension\Assistant\AssistantTool` uses plain PHP types, since the release prefixes PSR and Symfony:
- `name()`, which must start with the add-on's prefix and may not take a shop tool's name.
- `title()` and `description()`.
- `inputSchema()`: an array, JSON Schema 2020-12. It is checked when registered.
- `capability()`: the WordPress capability the user needs.
- `readOnly()` and `risk()`: one of the risk classes, or none.
- `run(array $input, ToolCall $call)`: returns an array for `structuredContent`, or throws `ToolRefused` with a
  message the model reads. `ToolCall` gives the user's id, the key's name, and `log()`.

The shop checks the capability and the risk before `run()`, and writes the call into the change log. An add-on's
REST routes stay its own; a tool can call its services directly.

**The owner chooses.** The key's screen lists the shop's areas and every add-on's tools beside them, with the
add-on's name. Each one is ticked or not per key. What a key leaves out is kept as a list, so an area that comes later (with an update or a
new add-on) is offered until the owner unticks it; its risky tools still wait for the owner's yes.

**WordPress's Abilities API.** On WordPress 6.9 and later the same catalogue is also registered as abilities
(category `orillacart`, `list_orders` as `orillacart/list-orders`), with the same schemas and permission checks.
WordPress validates an ability's input itself and follows no `$ref`, so the schemas are written out for it.
Abilities cannot ask the owner first, so a tool with a risk stays the MCP server's. WordPress's own MCP Adapter and its AI
features then see the shop too. The plugin's endpoint does not depend on it, because the plugin supports
WordPress 6.7.

## Later: OAuth for clients that cannot take a key

Claude.ai's and ChatGPT's connectors add a server by its address and log in with OAuth; they have no field for a
key. The plugin will be its own authorization server, as the spec describes:
- Protected resource metadata (RFC 9728) and authorization server metadata (RFC 8414).
- Client ID metadata documents, fetched with WordPress's safe HTTP; dynamic registration only as the spec's
  deprecated fallback.
- The authorization code flow with PKCE, the `resource` parameter checked, and `iss` in the response.
- The consent screen is in wp-admin: "Claude wants to manage your shop as Ana (read and change)". The owner sets
  the same choices as for a key.
- Tokens are short-lived, with refresh tokens. Each grant shows in the keys' list and is revoked there.

This is item 188. The key path is what the shop needs on the first day.

## Why not WordPress's MCP Adapter

WordPress's MCP Adapter turns abilities into MCP tools. The plugin does not build on it:
- **It is a separate plugin** in WordPress 7.0, not core, so "works once installed" would need a second install.
- **Bundling it is not safe.** Two plugins bundling it register the same REST routes, and prefixing it would not
  stop that.
- **It needs WordPress 6.9.** The plugin supports 6.7.
- **The shop's checks would be done twice**: its capability, key scope, validation and change log live in the
  HTTP kernel, which abilities do not pass through.

Registering the same tools as abilities (above) gives the adapter's users the shop anyway.

## What it cannot promise

- **A model can still be wrong.** It sees what the manager sees and can change what they can. Read-only keys,
  areas left out, and the ask-before classes are how the owner limits it.
- **Clients differ.** Some do not ask before a tool marked destructive, and some cannot do elicitation. Those
  get refused for the risk classes until the owner unticks them.
- **Long work runs in the background.** Imports, exports and reindexing return a job, and the model checks the job
  with its tool.

## Tests

- **Unit**: the protocol (each revision's requests, errors, headers that disagree with the body), schemas from
  DTOs, names, risk classes.
- **Integration**, on wp-env: every tool's schema is valid JSON Schema 2020-12; a call per area with a key; a
  read-only key refused; a risk class asked and then run; the change log names the assistant; add-on tools.
- **End to end**: the official MCP Inspector CLI lists the tools and calls a few against the test site, for both
  protocol revisions.
