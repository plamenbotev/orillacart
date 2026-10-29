<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\I18n;

/**
 * What WPML String Translation's functions were asked, as the stand-ins below record it.
 */
final class WpmlStringFunctions
{
    /** @var list<array{string, string, string}> value, context, name */
    public static array $strings = [];
    /** @var list<array{int, string, string, int}> string id, language, value, status */
    public static array $translations = [];

    public static function load(): void
    {
        require_once __DIR__ . '/wpml-string-functions.php';
        self::$strings = [];
        self::$translations = [];
    }
}
