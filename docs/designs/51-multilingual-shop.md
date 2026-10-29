# 51 · A shop in several languages

Status: agreed (2026-10-28); steps 1 to 4 built (PLAN 212, 214–216), the rest to do. PLAN items 212–217.

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
- **REST, the storefront blocks and MCP** take a language: their requests do not carry the plugin's URL language
  (the storefront's REST calls send the page's, step 2).
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
- **Deleting follows the product.** The original's translations go to the trash with it, come back with it (only
  those it took), and are deleted with it for good; a translation trashed or deleted is that language's page alone.
  A category, brand or tag deleted in any language is deleted in all of them (the owner's choice, 2026-10-29), so
  each language's products lose it, and rules, coupons and forms naming it stop matching, as for any deleted term.
  No foreign keys reach WordPress's posts or the plugins' translation groups, and one WordPress delete runs other
  plugins' hooks, so these follow WordPress's actions without a transaction around them; each step stands alone,
  and a translation whose delete failed has no product to sell.
- Known gaps for later steps: an email's product link is the original's address; search filters set for a
  category do not show on its translation's page; the admin product pickers list the posts of the admin's
  language.

## Built in step 2 (attributes first)

- **`{prefix}orillacart_translations`**: object type, object id, field, locale, value, unique by the first four.
  Keyed by WordPress locale (de_DE), not the plugin's code: emails switch to a locale, and the translations stay
  when a site moves from Polylang to WPML. The field's own column keeps the default language.
- **Areas declare what they translate** (`Translations\Application\TranslatableType`: a type, its fields with their
  longest value); `ContentTranslations` checks a translation against them and the
  site's languages, and reads one in the language shown.
- **Saved with the object** (the owner's choice, 2026-10-29): an attribute or option in a product's or an attribute
  set's save carries `translations` by locale, checked before anything is written and written in the same
  transaction once the save gave new ones their ids; `null` leaves them as they are. Removed attributes and options,
  deleted products and sets take theirs with them. The views return them; `GET /translations` reads any type's,
  `GET /content-languages` lists the languages.
- **The admin**: one `TranslationFields` component, a field per other language under the field itself, nothing on
  a site in one language; the attributes editor uses it for the product's and the sets' attributes and options.
- **The shopper** reads the names in the language shown, with the option's own name where it has no translation:
  offers, variation names and quotes (the cart line's options), read for a request in a few queries.
- **Shipping and payments** the same way: a carrier's name, its rates' and its pickup places' (name, address, hours),
  saved with the carrier; a payment method's name, instructions, receipt note and fee label, saved with it.
  A field of an item a list holds names the item's key: `pickup.{key}.name`, so a place gone takes its words with
  it. Checkout's options, the receipt note, the fee's line and the Shipping and payment block read them in the
  language shown; the order keeps the words it was placed with.
- **Forms and search filters** too: each field's label, help and placeholder and each choice's label, by the
  field's key and the choice's value (`field.{key}.choice.{key}`), saved with the form; a new field's key is made
  in the save, so its words are checked first and keyed after. The product page, the cart and checkout ask the
  questions in the language shown, and the answers keep the words they were asked in. A filter's label, saved
  with it, heads its group in the shop's filter panel.
- **Email templates, one per language.** A template the shop edits is for every language, or for one of the site's
  other languages, which its readers get instead; resetting a language's goes back to the one for every language.
  An email is written in its reader's language (an order's, the shopper's), and the template is looked up in it.
  The editor has a language to choose, and a preview of a language's template is written in that language.
  While the shop writes in a language its locale holds, also where WordPress has no files for it.
- **An order keeps both words** (the owner's report, 2026-10-29): the customer's, as placed, and the shop's own
  beside them where they differ, for its items (name, options, a bundle's contents, answers), the way it ships,
  the pickup place, the way to pay, the fees and checkout's answers. The shop's view reads its own: the admin,
  REST and MCP, the shop's emails, reports, webhooks, courier labels. The customer's emails, invoices, receipt and
  tracking read theirs (`OrderService::inCustomerWords`). An edit by the shop prices in the order's language, so
  both stay; a manual order is in the site's. The admin's order says which language it was placed in.
- **The storefront's calls carry the page's language.** The REST API's URL has no language of Polylang's or WPML's,
  so the cart, checkout and quotes would answer in the default one. The page gives its locale to the script, which
  sends it as `X-OrillaCart-Locale`; a locale the site offers is the call's: the content language, WordPress's
  locale (also without WordPress's files for it), and so the order's language for its emails. Others are ignored.

## Built in step 3

- **A search index document per product and language.** The product's own words are its document, in its post's
  language (a post the plugin gave none is in the default one); each published translation adds a document with
  its title and text, sharing what the product is filtered and sorted by. The database engine keeps them as rows
  of `search_documents` by product and locale; OpenSearch as documents of their own (`12@de_DE`), the product's
  listing the languages it has them in. A translation saved, trashed or deleted builds its original again.
- **A search looks in the language shown**: a product's words in it, else its own. Whether products not
  translated into it are listed at all is the owner's setting (Storefront: "List products not translated into
  the language shown", on by default, shown on a site in several languages).
- **Values are named in it**: a category's, brand's or tag's name from its translation, an option's from the
  shop's translations, kept beside the value (`search_values.labels`). Filters still go by the own names and ids,
  so a shopper's choices and links hold in every language.
- **The blocks** render in the page's language; in the editor, their previews through WordPress's block renderer
  take the language of the post edited. **The assistant's endpoint** takes `X-OrillaCart-Locale` as the REST API
  does. Related products find the products under each language's terms.
- The analyzers stay the shop's own (English stopwords); a language's own analysis in OpenSearch is for later.

## Built in step 4

- **One package per object** (`Translations\Application\TranslationPackages`): a carrier with its pickup places'
  and rates' words, a payment method, a form with its fields and choices, a shop filter, an attribute set with its
  options, a product's own attributes and options. Each area offers its kind as a `TranslationPackageSource`; the
  object's save registers its package with its words as they are, its delete removes it.
- **WPML String Translation** is the service (`WpmlStringPackages`), through its documented string package hooks:
  `wpml_start_string_package_registration`, `wpml_register_string`, `wpml_delete_unused_package_strings`,
  `wpml_delete_package`. A string is named by a hash of its field, so its words and title can change.
- **What translators finish comes back** into the translations table, which stays the truth the shop reads: read
  through `wpml_translate_string` in each language, after WPML completes or saves a translation job (a job on the
  `translations` lane), or with `wp orillacart translations pull`. A word WPML has translated replaces the shop's
  own translation of it in that language; the others stay. A translation the shop would refuse (too long) is left
  out. Activating or updating the plugin, or activating WPML String Translation, registers every object;
  `wp orillacart translations register` does too.
- Not here: translations typed in the shop's own screens are not sent to WPML, and email templates (one per
  language, step 2) have no package. WPML is commercial and not in the test environment: its hooks are answered by
  a stand-in, as WPML documents them.

## Order

1. The contract and its three implementations; translated products taken back to the original; translated product
   text through Polylang and WPML.
2. The translations table and the languages beside each field: attributes first, then shipping, payments, forms,
   filters, email templates.
3. The search index per language; a language for the blocks and MCP (the storefront's REST calls have theirs).
4. WPML string packages filling the table.
5. Later: a catalog shared by a network's language sites.
