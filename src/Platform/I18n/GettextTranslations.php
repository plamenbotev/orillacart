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
    /**
     * @param (\Closure(string): string)|null $gettext looks the words up; WordPress's __() in the plugin's domain
     */
    public function __construct(private ?\Closure $gettext = null) {}

    public function text(Text|string $text): string
    {
        if (\is_string($text)) {
            return $this->translate($text);
        }

        $values = array_map(fn(int|float|string|Text $value): int|float|string => $value instanceof Text ? $this->text($value) : $value, $text->values);
        $translated = $this->translate($text->template);
        // A translation that lost or changed a placeholder would show "%1$s", or leave a value out: the English
        // sentence with its values reads better than either.
        if ($translated !== $text->template && self::placeholders($translated) !== self::placeholders($text->template)) {
            $translated = $text->template;
        }

        return Text::format($translated, $values);
    }

    /**
     * The {{ parameters }} of a validation message, sorted.
     *
     * @return list<string>
     */
    private static function tokens(string $message): array
    {
        preg_match_all('/\{\{\s*\w+\s*\}\}/', $message, $found);
        $tokens = array_map(static fn(string $token): string => (string) preg_replace('/\s+/', '', $token), $found[0]);
        sort($tokens);

        return array_values(array_unique($tokens));
    }

    /**
     * The values a template takes, by position: "%1$s of %2$d" and "%s of %d" are both [1 => 's', 2 => 'd'].
     *
     * @return array<int, string>
     */
    private static function placeholders(string $template): array
    {
        preg_match_all('/%(?:(\d+)\$)?[-+ 0\'.\d]*([bcdeEfFgGosuxX])/', str_replace('%%', '', $template), $found, \PREG_SET_ORDER);
        $positions = [];
        $next = 1;
        foreach ($found as $placeholder) {
            $position = $placeholder[1] !== '' ? (int) $placeholder[1] : $next++;
            $positions[$position] = $placeholder[2];
        }
        ksort($positions);

        return $positions;
    }

    public function message(string $template, array $parameters = [], ?int $count = null): string
    {
        $forms = explode('|', $template);
        if (\count($forms) === 2) {
            $translated = \function_exists('_n') ? _n($forms[0], $forms[1], $count ?? 1, TextDomain::DOMAIN) : (($count ?? 1) === 1 ? $forms[0] : $forms[1]);
        } else {
            $translated = $this->translate($template);
        }
        $english = \count($forms) === 2 ? (($count ?? 1) === 1 ? $forms[0] : $forms[1]) : $template;
        if (self::tokens($translated) !== self::tokens($english)) {
            $translated = $english;
        }
        $values = [];
        foreach ($parameters as $name => $value) {
            $values[(string) $name] = \is_scalar($value) || $value instanceof \Stringable ? (string) $value : get_debug_type($value);
        }

        return strtr($translated, $values);
    }

    private function translate(string $text): string
    {
        if ($this->gettext !== null) {
            return ($this->gettext)($text);
        }
        // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- the words come from bin/message-strings.php's list
        return $text === '' || !\function_exists('__') ? $text : __($text, TextDomain::DOMAIN);
    }
}
