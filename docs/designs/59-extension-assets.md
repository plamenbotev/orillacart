# 59 · An extension's scripts and styles

Status: built (2026-11-09). PLAN items 362–365.

An extension's templates could already print `<script>` and `<style>` inline, and the guide's "use my points"
example did. Inline has costs: no browser caching, the same code on every page a slot is on, no reuse between a
slot and a page template, and nothing works under a nonce-based Content Security Policy. A buyer portal is a
few hundred lines of JavaScript; inline in a Twig slot it is unreadable. So an extension may carry files for the
browser, and a template says where they go.

## Decisions

**The template that needs the file is the declaration.** `{{ asset('portal.js') }}` in an extension's template
prints a deferred script tag; `asset('portal.css')` a stylesheet link; for an SVG the URL. The same node visitor
that binds `__()` to the extension binds `asset()`, so a template reaches only its own package's files, and the
name must be written as a string, so the static check can see the file is there. A declaration block (`assets {
script, style, on: [...] }`) was considered and dropped: it would have said where a file loads, which the
template already says by being rendered there.

**Files under `assets/` in the package**, `.js`, `.css` and `.svg` only, text like every other file of a package
(the editor shows them, the zip carries them, versions keep them). Images and fonts wait for binary files in
packages, which nothing needs yet. A script outside `assets/` in a zip is a tool's (`webpack.config.js`) and is
skipped, not refused.

**Served as files, not through REST.** The first time a page asks for one, the file is written under the site's
uploads, `uploads/orillacart/extensions/<id>/<name>-<hash>.<ext>`, and the web server serves it from then on
with long caching; a changed file is a new name, and the older one goes. On a network each site has its own
uploads. Routes stay JSON-only. Uninstalling removes the folder.

**Once per page, only where rendered.** A script or stylesheet is printed once however many slots ask for it,
and only on pages where a template of the extension rendered: a loyalty script does not load on the catalogue.
An SVG's URL is printed as often as asked.

**A permission, `assets: serve`.** Code in customers' browsers is something the manager grants, as `templates:
override` and `http` are. Without it, or disabled, nothing is served and the log says why. This makes explicit
what inline scripts in templates did quietly.

**What is left out.** A script on pages where the extension has no template (a tracking pixel everywhere): an
extension that wants to be on every page would need a slot every page has, which none does, by design. No
bundling, no TypeScript, no npm: the file is what runs.
