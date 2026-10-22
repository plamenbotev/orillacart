<?php

declare(strict_types=1);

namespace OrillaCart\Forms\Domain;

/**
 * What a shopper typed, made safe to keep and show: valid UTF-8, without control characters (a long text keeps its
 * line breaks) and without the characters that turn the direction of text, which make an answer read otherwise
 * than it is. Showing it still escapes it.
 */
final class CleanText
{
    private const string DIRECTION = '\x{202A}-\x{202E}\x{2066}-\x{2069}\x{200E}\x{200F}\x{061C}';

    /** Null for text that is not valid UTF-8. */
    public static function of(string $text, bool $lines = false): ?string
    {
        if (!mb_check_encoding($text, 'UTF-8')) {
            return null;
        }
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $control = $lines ? '\x{0000}-\x{0009}\x{000B}-\x{001F}' : '\x{0000}-\x{001F}';
        $text = (string) preg_replace('/[' . $control . '\x{007F}-\x{009F}' . self::DIRECTION . '\x{FEFF}]/u', '', $text);

        return trim($text);
    }

    /** A file's name as shown: without its path, cut to 100 characters, never empty. */
    public static function filename(string $name): string
    {
        $name = self::of(basename(str_replace('\\', '/', $name))) ?? '';
        $name = mb_substr($name, -100);

        return $name === '' || $name === '.' || $name === '..' ? 'file' : $name;
    }
}
