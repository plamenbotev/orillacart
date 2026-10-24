# 46 · Words in the site's language

Status: built (2026-10-24), PLAN item 153 (second audit).

## Before

Templates and scripts were translated, but what the PHP code said stayed English: the rule violations a shopper
or the shop sees ("This code has expired."), validation messages ("This value should not be blank."), gift-card
and coupon refusals, return reasons and the notes sent to customers about returns. The .pot dated from
2026-10-18.

## How it works

- **Text** (`Shared\Domain\Text`) is words in English with the values that go into them: "This gift card is in
  %1$s and cannot pay for an order in %2$s." and the two currencies. As a string it is the English sentence, for
  logs and tests. Its values may be Text of their own (a refused row's reason), translated too.
- **Rule violations keep their words apart from their values.** `new SomeRuleViolation('… %s …', code, values:
  [$name])`; `$e->text` is the Text and `getMessage()` the English. A violation passed on keeps them
  (`new CheckoutRuleViolation($e->text, …)`).
- **Translated where the words leave the plugin.** `Shared\I18n\Translations` (gettext, `__()` and `_n()` in the
  plugin's text domain) translates the template, then puts the values in: the REST errors (ErrorMiddleware), the
  pages that show an error (wp_die, the checkout block, quick and bulk edit), and every Text in a view, by the
  serializer's TextNormalizer, for the REST API and the templates alike.
- **Validation messages** are Symfony's templates with their `{{ limit }}` parameters and "one|many" forms,
  translated by RequestMapper with `_n()` for the count. A field message that is a Text is the shop's own words
  (forms, uploads) and is translated by the middleware; a plain string is final (an add-on's check).
- **Words stored for a person** are written in the shop's language when they are written: a gift card's label on
  the order, the notes about a return the customer reads. Notes for the shop, logs and job failures stay English.
- **Statuses inside a sentence** are their own words ("on hold"), so "Order #12 is on hold and …" translates whole.
- **A broken translation gives way to the English.** One whose placeholders differ from the English (a `%2$s`
  lost, a `{{ limit }}` dropped) would show "%1$s" or leave a value out: the English sentence, with its values,
  shows instead.

## The list for translators

`bin/message-strings.php` reads the PHP code (PHP-Parser) for the messages of violations, not-founds and HTTP
errors, `new Text`/`Text::of`, `Translations::text('…')`, the `refuse('…')` helpers, and the `#[Assert\…]`
messages with Symfony's defaults for the constraints the DTOs use; it writes them as `__()`/`_n()` calls for
`wp i18n make-pot` (`npm run i18n:pot`, which the release build now runs). It warns of a message whose
placeholders are not numbered: every message with more than one is `%1$s`, `%2$s`, so translators can reorder
them. A message the scripts or templates translate too keeps their note for translators, not a second one.
