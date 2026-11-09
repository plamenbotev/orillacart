<?php

declare(strict_types=1);

namespace OrillaCart\Shared\I18n;

/**
 * How many plural forms a language has and which one a count takes, by gettext's rules (the `Plural-Forms` of
 * its .po files): English two (1 item, 2 items), Polish three, Arabic six. A language not listed takes English's.
 */
final class PluralForms
{
    /** Languages with one form, whatever the count. */
    private const array ONE = ['ja', 'ko', 'zh', 'vi', 'th', 'id', 'ms', 'km', 'lo', 'my', 'ka', 'bo', 'dz', 'jv', 'su', 'ky', 'tt', 'ug', 'yo'];

    /** Languages whose singular takes 0 and 1 (`n > 1`). */
    private const array ZERO_IS_ONE = ['fr', 'tr', 'hy', 'oc', 'br', 'fa', 'am', 'ln', 'mg', 'ti', 'wa', 'fil', 'tl', 'kab'];

    /** Languages with three forms by the last digits (`n%10==1 && n%100!=11`; 2–4; the rest). */
    private const array SLAVIC = ['ru', 'uk', 'be', 'sr', 'hr', 'bs'];

    /** The number of forms a locale's language has. */
    public static function count(string $locale): int
    {
        return match (self::family($locale)) {
            'one' => 1,
            'slavic', 'pl', 'cs', 'lt', 'lv', 'ro' => 3,
            'sl', 'cy' => 4,
            'ga' => 5,
            'ar' => 6,
            default => 2,
        };
    }

    /** The form a count takes, from 0 to count() - 1. */
    public static function index(string $locale, int $n): int
    {
        $n = abs($n);
        $mod10 = $n % 10;
        $mod100 = $n % 100;

        return match (self::family($locale)) {
            'one' => 0,
            'zero-is-one' => $n > 1 ? 1 : 0,
            'is' => $mod10 !== 1 || $mod100 === 11 ? 1 : 0,
            'mk' => $mod10 === 1 && $mod100 !== 11 ? 0 : 1,
            'slavic' => $mod10 === 1 && $mod100 !== 11 ? 0 : ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 10 || $mod100 >= 20) ? 1 : 2),
            'pl' => $n === 1 ? 0 : ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 10 || $mod100 >= 20) ? 1 : 2),
            'cs' => $n === 1 ? 0 : ($n >= 2 && $n <= 4 ? 1 : 2),
            'lt' => $mod10 === 1 && $mod100 !== 11 ? 0 : ($mod10 >= 2 && ($mod100 < 10 || $mod100 >= 20) ? 1 : 2),
            'lv' => $mod10 === 1 && $mod100 !== 11 ? 0 : ($n !== 0 ? 1 : 2),
            'ro' => $n === 1 ? 0 : ($n === 0 || ($mod100 > 0 && $mod100 < 20) ? 1 : 2),
            'sl' => $mod100 === 1 ? 0 : ($mod100 === 2 ? 1 : ($mod100 === 3 || $mod100 === 4 ? 2 : 3)),
            'cy' => $n === 1 ? 0 : ($n === 2 ? 1 : ($n !== 8 && $n !== 11 ? 2 : 3)),
            'ga' => $n === 1 ? 0 : ($n === 2 ? 1 : ($n < 7 ? 2 : ($n < 11 ? 3 : 4))),
            'ar' => $n === 0 ? 0 : ($n === 1 ? 1 : ($n === 2 ? 2 : ($mod100 >= 3 && $mod100 <= 10 ? 3 : ($mod100 >= 11 ? 4 : 5)))),
            // English's, and every language with one form for 1 and another for the rest.
            default => $n === 1 ? 0 : 1,
        };
    }

    private static function family(string $locale): string
    {
        $parts = explode('_', str_replace('-', '_', strtolower($locale)));
        $language = $parts[0];
        if ($language === 'pt') {
            // Brazil's Portuguese counts 0 as one, Portugal's does not.
            return ($parts[1] ?? '') === 'br' ? 'zero-is-one' : 'two';
        }

        return match (true) {
            \in_array($language, self::ONE, true) => 'one',
            \in_array($language, self::ZERO_IS_ONE, true) => 'zero-is-one',
            \in_array($language, self::SLAVIC, true) => 'slavic',
            \in_array($language, ['pl', 'cs', 'lt', 'lv', 'ro', 'sl', 'cy', 'ga', 'ar', 'is', 'mk'], true) => $language,
            $language === 'sk' => 'cs',
            default => 'two',
        };
    }
}
