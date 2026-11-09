# 57 · Extensions in the site's languages

Status: built (2026-11-09). PLAN items 341–346.

Design 56 gave each shop extensions written in its own script language; design 51 gave the shop's own words a
translation in each language a site offers. An extension's words had none: what its scripts returned, what its
templates printed and what it declared (a field's label, a payment method's name, a setting's help) were shown as
written, in every language. Its templates could call `__()`, but that looked in the plugin's own `.mo`, which has
none of an extension's words.

## Decisions

**An extension's words are translated as the shop's own are: in the translations table** (design 51), object type
`extension`, the extension's id, one field per word. Not in `.po` files among its files: the table is already the
truth the shop reads, WPML's string packages already fill it, and the shop's screens already edit it. An export
carries them beside the files, so an extension moved to another shop keeps its translations.

**A word is its text, as gettext's are.** The field is `word.<key>`, the key the first 16 hex characters of the
SHA-256 of the context and the words (`context\x04words`, the plural's `words\x00plural`). The same words in two
places are translated once; `_x()` tells them apart where they mean different things. Changing the English is a
new word, as in gettext: its old translation stays in the table, unused, until the extension is uninstalled.

**Marked in code, implied in declarations.**
- Scripts and templates mark what they show with `__("words")`, `_x("words", "context")`,
  `_n("one", "many", count)` and `_nx("one", "many", count, "context")`, the names WordPress and the shop's own
  templates use. The words must be written in the call: the static check refuses `__(name)`, since nothing could
  list such words for a translator. The values go in after translating (`__("%s left")|replace({"%s": n})`).
- Declarations need nothing: the words a person reads are known by where they stand. The extension's `name`; a
  setting's `label`, `help` and the labels of its `of` choices; a provider's `name` (payment gateways, shipping
  methods and every other kind the registrant adapts); a column's, filter's and order action's `label`; a
  checkout field's `label`, `help` and `choices` labels; a field on a record's `label` and `help`; a product
  block's `title` and `description`. What an assistant tool or a manager route says of itself (`title`,
  `description`) is for a model and stays as written.

**Which language.** The one the shop shows words in (`ContentTranslations::shownLocale`): the page's or the call's
(`X-OrillaCart-Locale`), an email's reader's, the admin user's. In the default language, or on a site in one
language, the extension's own words stand.

**When the extension has no translation, the shop's.** `__("Free shipping")` in an extension reads the plugin's
`.mo` when the extension's table has nothing for it, so the words the shop already translates need nothing more.
The extension's own translation wins where both exist.

**Plurals** follow the language's rule: how many forms it has and which a count takes (English two, Polish three,
Arabic six), from a table of gettext's plural rules by language; a language it does not know takes English's. The
translation of each form is its own field, `word.<key>` for the first and `word.<key>-<n>` for the others. A form
left empty takes the nearest one before it that is filled in, then the extension's own words.

**Templates.** An extension's own templates (`x/<extension>/…`) and its overrides of the shop's are marked by the
loader with the extension they belong to; a Twig node visitor turns their `__`, `_x`, `_n` and `_nx` into the
same lookup with that extension's id, after the sandbox has checked the names. The plugin's own templates are
unchanged. The words of a call that are not written as a string are refused when the template is compiled, which
the save's check does, as it refuses any template that does not compile.

## How it fits

- **`language()`** gives a script the locale words are shown in (`"de_DE"`), for what translation cannot do
  (a date written the language's way, a provider called in it).
- **The words an extension has** (`ExtensionWords`): the declarations', each script call's and each template's,
  with where each stands (`main.ocs, line 12`, `settings: merchant_id, label`, `templates/support.html.twig`).
- **The Extensions screen** has a Translations section on a site in several languages: each word with a field per
  other language (as many as the language has forms for a plural), saved together with
  `PUT /extensions/{id}/translations`; `GET` gives the words, the languages with their plural forms, and what is
  translated. Both are assistant tools, as every manager route is.
- **WPML**: each extension is a string package (`ExtensionPackages`), registered when its files are saved,
  imported or restored, removed when it is uninstalled, its translators' work pulled back as other packages' is.
  WPML's packages have one string per word, so a plural gets two (the one and the many); a language with more
  forms is completed in the shop's screen.
- **Export and import** carry `translations` (by key and locale, the forms as a list); an import writes those of
  the site's languages. Uninstalling forgets them.
- **Docs**: the guide's "Words in other languages", the reference's new functions, this design.

## Left out

- Search facets from fields (`filter: true`) are named by the field's declared label in every language: the
  facet's name is the attribute it is indexed under, which must be one name.
- An assistant tool's and a route's descriptions are not translated (above).
- `.po`/`.mo` files in an extension are not read; the table is the one place.
