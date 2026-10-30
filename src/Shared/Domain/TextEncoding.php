<?php

declare(strict_types=1);

namespace OrillaCart\Shared\Domain;

/**
 * Reads a file a spreadsheet saved: UTF-8 as it is; otherwise Windows-1252, as Excel on Windows saves "CSV"
 * there, converted to UTF-8. Bytes that are neither are not text the shop can read.
 */
final class TextEncoding
{
    /** The bytes Windows-1252 leaves undefined. */
    private const string UNDEFINED_1252 = '/[\x81\x8D\x8F\x90\x9D]/';

    /** @return string|null the text in UTF-8; null for bytes that are neither UTF-8 nor Windows-1252 */
    public static function utf8(string $bytes): ?string
    {
        if (mb_check_encoding($bytes, 'UTF-8')) {
            return $bytes;
        }
        if (preg_match(self::UNDEFINED_1252, $bytes) === 1) {
            return null;
        }

        return mb_convert_encoding($bytes, 'UTF-8', 'Windows-1252');
    }
}
