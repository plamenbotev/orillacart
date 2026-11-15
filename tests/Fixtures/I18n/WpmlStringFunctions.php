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
    /** @var array<int, array<string, array{value: string, status: int}>> WPML's translations by string id and language */
    public static array $finished = [];
    /** @var array<string, int> the strings' ids by name; 77 for any other */
    public static array $ids = [];

    public static function load(): void
    {
        require_once __DIR__ . '/wpml-string-functions.php';
        self::reset();
    }

    /**
     * Forgets what the functions were asked and answer: once loaded they stay for the process, and a test after one
     * that loaded them finds them with nothing finished, as WPML without translations answers.
     */
    public static function reset(): void
    {
        self::$strings = [];
        self::$translations = [];
        self::$finished = [];
        self::$ids = [];
    }
}
