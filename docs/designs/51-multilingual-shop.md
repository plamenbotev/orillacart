# 51 · A shop in several languages

Status: agreed (2026-10-28), not built. PLAN items 212–217.

Design 46 translates the shop's own words. This one is about the shop's content: products, categories, attributes,
shipping and payment titles, forms, filters and email templates in each language a site offers.

## Two ways, both possible

- **A site per language on a network** works today: each site is a shop of its own, with its own catalog, stock and
  settings. A catalog shared by the language sites of a network (one stock) is a later, separate step.
- **One site in several languages**, with Polylang or WPML: they are what WordPress sites use, so the shop works
  with them and does not bring a language system of its own.

## Decisions

- **The original is the product; its translations are text.** Polylang and WPML translate a post into another post.
  For the shop the post in the original language is the product: price, stock, SKU, variations, orders, reports and
  coupons are its own. A translation gives the title, description, excerpt and slug only. A translated post's id is
  taken back to the original wherever the shop meets one (cart, blocks, REST, MCP), so stock never splits.
  Categories are terms, translated by the plugins the same way.
- **The shop's own translations table is the source of truth** for what lives in the shop's tables: attribute names
  and options, attribute sets, shipping and payment method titles, carriers, form labels, search filter labels,
  email templates. A row is object type, object id, field, language and value; the field's own column holds the
  original language. The admin screens edit the languages beside each field. WPML string packages may fill it
  (one package per object, for its translation management and services); Polylang, which has nothing alike, uses
  the shop's screens.

## How it fits

- **A multilingual contract** grows from `Languages`: the site's languages, the default and the current one, a post's
  or term's translation in a language and its original. Three implementations, chosen at boot: none (one language),
  Polylang, WPML. Add-ons get it through the extension API.
- **The product editor** is the shop's own screen, where the plugins' language boxes do not show: it gets the
  language switch and "translate this product" itself, through the plugins' APIs.
- **The search index** holds a document per product and language; a search looks in the current language.
- **REST, the storefront blocks and MCP** take a language: their requests do not carry the plugin's URL language.
- **Orders** keep the customer's language and send emails in it already; the email templates get one per language.
- **Not here:** a currency per language belongs to multi-currency.

## Order

1. The contract and its three implementations; translated products taken back to the original; translated product
   text through Polylang and WPML.
2. The translations table and the languages beside each field: attributes first, then shipping, payments, forms,
   filters, email templates.
3. The search index per language; a language for REST, the blocks and MCP.
4. WPML string packages filling the table.
5. Later: a catalog shared by a network's language sites.
