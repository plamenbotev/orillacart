# The AI assistant (MCP)

The shop has an MCP server built in. An AI client that speaks MCP (Claude, Cursor, VS Code and others) can
manage the shop through it, as the shop manager whose API key it uses. Every change it makes is in the change
log, marked "through the AI assistant" with the key's name.

## Connect

1. In Shop → Integrations, make an API key. A **read** key lets the assistant look at everything and change
   nothing; a **read and write** key lets it do what its manager can.
2. Give your client the endpoint and the key:

   ```
   https://your-shop.example/wp-json/orillacart/v1/mcp
   Authorization: Bearer oc_…
   ```

   Without pretty permalinks the endpoint is `https://your-shop.example/?rest_route=/orillacart/v1/mcp`.

**Claude Code**

```sh
claude mcp add --transport http my-shop https://your-shop.example/wp-json/orillacart/v1/mcp \
  --header "Authorization: Bearer oc_…"
```

**Cursor** (`~/.cursor/mcp.json`) and **VS Code** (`.vscode/mcp.json`, under `"servers"` with `"type": "http"`)

```json
{
  "mcpServers": {
    "my-shop": {
      "url": "https://your-shop.example/wp-json/orillacart/v1/mcp",
      "headers": { "Authorization": "Bearer oc_…" }
    }
  }
}
```

**Claude Desktop** takes a server that needs a header through `mcp-remote`:

```json
{
  "mcpServers": {
    "my-shop": {
      "command": "npx",
      "args": ["mcp-remote", "https://your-shop.example/wp-json/orillacart/v1/mcp", "--header", "Authorization:Bearer oc_…"]
    }
  }
}
```

Some hosts drop the `Authorization` header before PHP sees it. Send `X-OrillaCart-Key: oc_…` instead.

Shop → Integrations shows the endpoint, these lines with a new key filled in, and a button that checks a key
reaches the shop.

## What each key's assistant may do

In Shop → Integrations, **Choose…** beside a key sets, for the assistant that uses it:
- **Ask me first when**: money moves, something is deleted, customers hear of it, or the shop opens or closes.
  All four are ticked to start. A client that can ask you (MCP elicitation, revision 2026-07-28) shows the
  question in its own window, and the tool runs only on your yes. A client that cannot ask is refused for those
  tools, and the assistant is told to leave them to you.
- **What it may use**: the shop's areas and each add-on's tools. An area added later is offered until you untick
  it.
- **How the tools are listed**: all of them (about 230), or three tools that find, describe and run the others,
  for clients that take few tools.

A read key is offered only the tools that read, whatever is ticked.

## What it can do

Every screen of the shop's admin has its tools: orders, products, customers, stock, tax, shipping, payments,
coupons, reports, emails, settings and the setup, one tool per step. Products' names, texts, categories and
pictures, the categories themselves, the media library's pictures and the site's pages have their own tools.
Add-ons can add theirs (docs/extensions.md, "Tools for the AI assistant"). A tool runs the same request the admin's screen sends, with
the same checks, so it can do nothing the manager could not. Some parts are left out on purpose: API keys and
webhooks are managed by a manager logged in, never with a key.

For products, a file (an import, a download) is sent as base64 or as an address the shop fetches. The shop
fetches only public addresses, up to 100 MB.

Besides tools, the server offers a resource, `orillacart://shop`, with the shop at a glance (name, country,
currency, whether visitors can order, what still stops it), and three prompts: **Set up my shop**, **Review
today's orders** and **What is running low**.

## Protocol

The endpoint speaks MCP's Streamable HTTP transport with JSON answers: revision 2026-07-28 (stateless,
`server/discover`) and the earlier 2025-11-25, 2025-06-18 and 2025-03-26 with their `initialize` handshake. It
keeps no session and opens no stream; `GET` answers 405. A browser's `Origin` must be the site's own, or one the
`orillacart_assistant_origins` filter adds:

```php
add_filter('orillacart_assistant_origins', static fn(array $origins): array => [...$origins, 'http://localhost:6274']);
```
