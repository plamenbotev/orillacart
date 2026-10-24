<?php

declare(strict_types=1);

namespace OrillaCart\Platform\I18n;

use OrillaCart\Platform\WordPress\Translations as TextDomain;
use OrillaCart\Shared\Domain\Text;
use OrillaCart\Shared\I18n\Translations;

/**
 * Translations from the plugin's .mo files, as WordPress loads them for the site's or the user's language. Without
 * WordPress (unit tests, the command line before it loads) the English stays.
 */
final readonly class GettextTranslations implements Translations
{
    public function text(Text|string $text): string
    {
        if (\is_string($text)) {
            return self::translate($text);
        }

        return Text::format(self::translate($text->template), array_map(fn(int|float|string|Text $value): int|float|string => $value instanceof Text ? $this->text($value) : $value, $text->values));
    }

    public function message(string $template, array $parameters = [], ?int $count = null): string
    {
        $forms = explode('|', $template);
        if (\count($forms) === 2) {
            $translated = \function_exists('_n') ? _n($forms[0], $forms[1], $count ?? 1, TextDomain::DOMAIN) : (($count ?? 1) === 1 ? $forms[0] : $forms[1]);
        } else {
            $translated = self::translate($template);
        }
        $values = [];
        foreach ($parameters as $name => $value) {
            $values[(string) $name] = \is_scalar($value) || $value instanceof \Stringable ? (string) $value : get_debug_type($value);
        }

        return strtr($translated, $values);
    }

    private static function translate(string $text): string
    {
        // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- the words come from bin/message-strings.php's list
        return $text === '' || !\function_exists('__') ? $text : __($text, TextDomain::DOMAIN);
    }
}
