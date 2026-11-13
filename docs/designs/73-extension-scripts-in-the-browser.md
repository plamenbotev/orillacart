# 73 · Extensions' code in the browser, and richer blocks

Status: built (2026-11-13). Extension API 28. PLAN items 641–651.

An extension's server-side script runs in the sandbox, held to the permissions the manager granted. What it sends
to the browser was not. A template could hold `<script>` and `onclick=` and print the REST nonce (`api_nonce()`),
with no permission at all; with `assets: serve`, its files ran in wp-admin as the manager, able to call any of
WordPress's endpoints and read any screen. So the permissions held the script, not the extension. And a complex
admin screen or block needs real JavaScript: forbidding it is no answer either.

## Decisions

**What an extension's template prints is cleaned, as it prints it.** Escaping is not enough: `href="{{ url }}"`
escapes a `javascript:` URL into a working one, and `<scr{{ "ipt" }}>` is a tag only once printed. So the body,
every block and every macro of a template from an extension's package (its own, `x/<extension>/…`, and its
overrides of the shop's) is captured and cleaned with WordPress's HTML API before it is printed
(`ExtensionOutputVisitor`, `ScriptFreeHtml`). Taken out: `<script>` of any type but `application/json` and
`application/ld+json` (it stays as data, its `src` gone), every `on…` attribute, a URL of the `javascript:`,
`vbscript:` or `data:` kind in any attribute (`data:image/…` in an image's `src` stays), `srcdoc`, `<base>`'s
href, `<meta http-equiv>`, `<object>`, `<embed>` and `<applet>`'s source. A tag left open at the end of a piece
is escaped, so a piece cannot run into what the shop prints after it. A template the shop includes from one of
the extension's is cleaned with it: the shop's own templates hold no script but data. The theme's templates and
the untrusted folders' (design 65) are not touched: an add-on that offers those decides who writes them.

**And the check says so first.** The static check reads each template's text and refuses an inline `<script>`
(not data) and an `on…` attribute, saying to move the code to a file under `assets/`. Data a script needs goes in
with `json_data(value)`, a `<script type="application/json">` the browser never runs, its text safe inside the
tag. `api_nonce()` is gone: a script calls the extension's routes through `window.orillacart.api()`.

**Scripts are a permission of their own.** `assets: serve` serves stylesheets and SVG; `assets: scripts` serves
the package's `.js` too, and the manager reads, granting it, what it means: code that runs in the browser of
whoever opens a page it is on, the manager included. In the storefront's own page nothing stronger is possible: a
script there is on the site's origin, with whatever the page holds. That is why it is asked for by name. A
script `asset()` printed is the one kind the cleaning lets run: its address, served this request, and `defer`.

**An extension's admin screen runs in a frame of its own.** The screen is a sandboxed iframe
(`sandbox="allow-scripts"`, no `allow-same-origin`): an origin of its own, so no cookies, no nonce, nothing of
the admin's page within reach; its Content-Security-Policy runs only the shop's bridge and the extension's own
files, and connects nowhere (`connect-src 'none'`). Inside, any JavaScript the package ships: a framework, a
bundle, a library. The bridge (`window.orillacart`, the shop's file) is the screen's way out, by `postMessage`
to the admin, which checks every message: `api(method, path, body)` calls the extension's own routes only (the
nonce added outside the frame, the route's permission checked as ever), `notice(text, tone)`, `navigate(url)` to a
screen of the admin, `media({ type, multiple })` the media library's picker. Links in the frame open in the
admin when they point into it, and nowhere otherwise. The frame takes WordPress's admin styles and grows to its
content. A form cannot be sent from the frame (no `allow-forms`): a screen that changes things does so through
its routes, as before. What the frame cannot be kept from is leaving with what it holds by moving itself to
another address: it holds only what the extension's handler and routes gave it.

**Blocks get the rest.** A `product_block` or `site_block` may name `assets` (a block's own scripts and
stylesheets, loaded on the pages it is placed on), and a `site_block` `inner: true` (blocks placed inside it,
their HTML given to `render` as `content`). Settings gain `image` (the media library's), `link`, `color`,
`product`, `category`, `rich_text` (cleaned as a post) and `list` (items, each of settings of its own: slides,
cards, tabs). A block's settings may be edited by the extension's own screen (`editor: "file.js"`): the same
frame and bridge in the block's sidebar, with `block.get()` and `block.set(settings)`; the canvas still shows
the block as the server renders it. `render` learns where it is (`context: { post_id, preview, content }`). A
`pattern` declaration names a package's `patterns/*.html`: a layout of blocks the editor offers, cleaned as a
template prints, refused when its blocks' attributes hold HTML (a block may print one as it is). PHP add-ons have
the same (API 28): `PlacedSiteBlock`, `BlockPlace`, `BlockEditor`, the block kinds of `Setting`, `BlockPattern`.

## Not done

Editor code on the canvas itself: a block's preview is the server's, as WordPress's own dynamic blocks are. A
Content-Security-Policy for the whole storefront: themes and plugins print inline scripts, so none would hold.
