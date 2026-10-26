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

## What it can do

Every screen of the shop's admin has its tools: orders, products, customers, stock, tax, shipping, payments,
coupons, reports, emails, settings and the setup. A tool runs the same request the admin's screen sends, with
the same checks, so it can do nothing the manager could not. Some parts are left out on purpose: API keys and
webhooks are managed by a manager logged in, never with a key.

For products, a file (an import, a download) is sent as base64 or as an address the shop fetches. The shop
fetches only public addresses, up to 100 MB.

## Protocol

The endpoint speaks MCP's Streamable HTTP transport with JSON answers: revision 2026-07-28 (stateless,
`server/discover`) and the earlier 2025-11-25, 2025-06-18 and 2025-03-26 with their `initialize` handshake. It
keeps no session and opens no stream; `GET` answers 405. A browser's `Origin` must be the site's own, or one the
`orillacart_assistant_origins` filter adds:

```php
add_filter('orillacart_assistant_origins', static fn(array $origins): array => [...$origins, 'http://localhost:6274']);
```
