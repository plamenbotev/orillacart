# 51 · A shop in several languages

Status: agreed (2026-10-28); step 1 built (PLAN 212), the rest to do. PLAN items 212–217.

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
- **The product editor** is WordPress's block editor with the shop's panel, so the plugins' own language boxes and
  "translate" links show there. On a translation the shop's panel edits the original's price, stock and variations
  (they are one product): it says so, with a link to the original.
- **The search index** holds a document per product and language; a search looks in the current language.
- **REST, the storefront blocks and MCP** take a language: their requests do not carry the plugin's URL language.
- **Orders** keep the customer's language and send emails in it already; the email templates get one per language.
- **Not here:** a currency per language belongs to multi-currency.

## Built in step 1

- `Shared\I18n\ContentLanguages` with `OneLanguage`, `PolylangLanguages` (its `pll_*` functions) and
  `WpmlLanguages` (its documented filters), chosen on first use after the plugins load. Polylang is told to
  translate products, categories, brands and tags (`pll_get_post_types`, `pll_get_taxonomies`); WPML reads
  `wpml-config.xml`. Variations are not translated as posts.
- **Which post is the product** (`Catalog\Application\ProductTranslations`): the one with the shop's details;
  with none yet, the default language's, so a save from any translation goes there; with several (two products
  linked as translations later), the oldest. Polylang has no original of its own and WPML's is not used, so both
  plugins behave alike.
- Taken back to the original: offers, quotes and the cart line, product saves, variations and downloads, bulk edits
  (once per product), the search index, reviews and stars, wishlists, back-in-stock requests, add-on marks, and
  the products coupons, price rules, forms and gift card settings name.
- Shown from the translation in the request's language: offer and cart line names, product links, product
  cards' pictures and excerpts, cards in the Products block, the product title's link, the shop pages' links.
- Terms count in every language: a category, tag or brand page lists the products of its translations, and a price
  rule or coupon for a category holds for the products in its translations.
- The assistant's product tools say a post's language and which product a translation is of. Add-ons get
  `Extension\Catalog\ProductLanguages` (version 17).
- Known gaps for later steps: an email's product link is the original's address; search filters set for a
  category do not show on its translation's page; deleting the original post deletes the product, leaving its
  translations without one; the admin product pickers list the posts of the admin's language.

## Order

1. The contract and its three implementations; translated products taken back to the original; translated product
   text through Polylang and WPML.
2. The translations table and the languages beside each field: attributes first, then shipping, payments, forms,
   filters, email templates.
3. The search index per language; a language for REST, the blocks and MCP.
4. WPML string packages filling the table.
5. Later: a catalog shared by a network's language sites.
