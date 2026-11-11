# 65 · Templates others write, and site blocks

Status: built (2026-11-11). Extension API 24. PLAN items 545–548.

A theme the shop's owner uploads, or templates an add-on lets editors write, are templates nobody vetted as PHP.
OrillaCart read a theme's `orillacart/` folder as trusted (a site trusts its theme), and an add-on had no way to say
otherwise, nor to render the storefront's templates or give them a filter. And an add-on could give blocks to
product pages only. Each is a small, general extension point; what a hosted platform builds on them is its own.

## Decisions

**Untrusted template folders** (`Registry::untrustedTemplates(UntrustedTemplateFolders)`). An add-on names folders
of storefront templates it does not trust with PHP. They are looked in after the scripted extensions' templates and
before the theme's and the shop's own, by the same names. Their templates render as an extension's: in the sandbox
(the storefront's tags, filters and functions, no methods, properties or PHP functions such as `constant`) and on
its loop budget. Their compiled form is cached under a key of its own, so a file once compiled as trusted never
runs as untrusted. The folders are asked for on the first template looked up, not when Twig is made, so every
add-on has hooked in.

**Rendering the storefront's templates** (`Registry::templates(): Templates`). `render(name, context)` renders a
template as the shop does, the sandboxed ones in the sandbox; `exists(name)`. A missing template or one the sandbox
refuses throws `TemplateFailed`.

**Template filters** (`Registry::templateFilter(TemplateFilter)`). A filter for every storefront template, the
sandboxed ones included; its answer escaped unless it says it is HTML. Twig would let a later filter replace one of
the shop's, so one named as a filter the shop has is left out and logged. Read when Twig sets itself up.

**Site blocks** (`Registry::siteBlock(SiteBlock)`, and `site_block` in scripts). A block for anywhere the editor or
the Site Editor takes one, registered as `orillacart-addon/{id}` beside the product blocks (an id is one block's,
product or site), with text, number, boolean and choice settings the owner fills in its sidebar. `render()` gets
what was set, checked against the settings; a script's HTML is cleaned as a product block's.

**The shop's own templates, to start an override from** (API 25). `Templates::names()` lists the shop's own
storefront and email templates, the ones an override may replace, and `Templates::original(name)` gives one's source
as it ships, whatever overrides it now: what an editor of a theme or an untrusted folder starts from.

## Left out

Product context for site blocks (product blocks have it). Image and colour settings (text holds an address or a
colour for now).
