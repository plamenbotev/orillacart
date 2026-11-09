# 60 · An extension's screens in the Shop menu

Status: built (2026-11-09). PLAN items 366–367.

An extension that keeps records (tickets, quotes, companies) needs a place in wp-admin where the shop manager
works with them. The Extensions screen is the developer's console, not the manager's desk; the assistant's
tools are for a model; a `page` is the storefront's. So an extension may declare screens of its own under the
Shop menu.

## Decisions

**`admin_page "/path" { title, template } fn(request)`**, declared as a `page` is. Each enabled extension's
screens are entries of the Shop menu, titled as declared, after the shop's own groups and before System, at
`admin.php?page=orillacart-x-<extension>-<path>`. The manager's capability (`manage_orillacart`) opens them;
no permission of the extension's is needed, since the screen is the manager's own.

**The screen is the extension's template**, rendered in the sandbox inside the admin's frame (`.wrap` and the
title), with what the handler answers; the handler gets the request as a route's, with the screen's query
string, as the manager. A refusal shows its message; an error shows a notice and is logged under the extension.
WordPress's admin classes are at hand for a plain, consistent look; the shop's React frame and tabs are not,
since a screen built from a template needs none of it.

**Changes go through routes.** The screen's script, printed with `asset()` (design 59), calls the extension's
manager routes with `wp.apiFetch`, which the shop enqueues on these screens and which carries the REST nonce; the
routes are the same ones the assistant has as tools, so a screen and the assistant do the same things the same
way. A screen never receives a POST of its own.

**What is left out.** Tabs under the shop's groups, badges in the menu, and screens for roles other than the
manager. Each is a declaration property away if a real extension needs it.
