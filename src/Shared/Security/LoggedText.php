<?php

declare(strict_types=1);

namespace OrillaCart\Shared\Security;

/**
 * What the shop writes to its logs is text other people sent: a customer's email in a mail failure, a VAT number
 * the registry would not check, a provider's answer. A log is read by more people than the order was, kept longer,
 * and may be served by the web server, so each value is written on one line (line breaks and control characters
 * would start a line of their own, made up by whoever sent them), and emails and VAT numbers are left out.
 */
final class LoggedText
{
    public const string EMAIL = '[email]';
    public const string VAT_NUMBER = '[VAT number]';

    private const string EMAILS = '/[\p{L}\p{N}._%+\-]+@[\p{L}\p{N}\-]+(?:\.[\p{L}\p{N}\-]+)+/u';
    private const string EMAILS_AS_BYTES = '/[A-Za-z0-9._%+\-\x80-\xFF]+@[A-Za-z0-9\-\x80-\xFF]+(?:\.[A-Za-z0-9\-\x80-\xFF]+)+/';
    /** A VAT number as the shop keeps it: the country's letters, then eight to twelve letters and digits. */
    private const string VAT_NUMBERS = '/(?<![A-Za-z0-9])(?:AT|BE|BG|CY|CZ|DE|DK|EE|EL|ES|FI|FR|GB|HR|HU|IE|IT|LT|LU|LV|MT|NL|PL|PT|RO|SE|SI|SK|XI)[A-Z0-9]{8,12}(?![A-Za-z0-9])/';
    /** A word of capitals is a VAT number only with this many digits in it. */
    private const int VAT_DIGITS = 6;
    private const string ABOUT = 'problem-about';

    /** The value on one line: every run of line breaks and control characters is a space. */
    public static function oneLine(string $value): string
    {
        // Unicode's control characters (the C0 and C1 sets) and its line and paragraph separators.
        $line = preg_replace('/[\p{Cc}\x{2028}\x{2029}]+/u', ' ', $value);

        // Text that is not UTF-8 is cleaned byte by byte.
        return $line ?? (string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $value);
    }

    /** The text without the emails and VAT numbers in it. */
    public static function withoutPeople(string $text): string
    {
        $text = preg_replace(self::EMAILS, self::EMAIL, $text) ?? (string) preg_replace(self::EMAILS_AS_BYTES, self::EMAIL, $text);

        return (string) preg_replace_callback(
            self::VAT_NUMBERS,
            static fn(array $match): string => preg_match_all('/\d/', $match[0]) >= self::VAT_DIGITS ? self::VAT_NUMBER : $match[0],
            $text,
        );
    }

    /**
     * The emails the text mentions, in lower case, each once: whom a logged problem is about.
     *
     * @return list<string>
     */
    public static function emails(string $text): array
    {
        if (preg_match_all(self::EMAILS, $text, $matches) === false) {
            preg_match_all(self::EMAILS_AS_BYTES, $text, $matches);
        }

        return array_values(array_unique(array_map(mb_strtolower(...), $matches[0] ?? [])));
    }

    /**
     * What a kept problem about this person is marked with in place of their email, for finding it when they are
     * erased: a signature only this site can make, so the mark does not tell whose it is. Null where the site's
     * key cannot be read.
     */
    public static function aboutMark(?Signatures $signatures, string $email): ?string
    {
        try {
            return $signatures === null ? null : substr($signatures->sign(self::ABOUT, mb_strtolower(trim($email))), 0, 16);
        } catch (\Throwable) {
            // WordPress has its salts only once plugins have loaded: a problem logged before is about nobody.
            return null;
        }
    }
}
