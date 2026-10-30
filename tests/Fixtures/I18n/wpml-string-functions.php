<?php

declare(strict_types=1);

use OrillaCart\Tests\Fixtures\I18n\WpmlStringFunctions;

// WPML String Translation's functions, as stand-ins: a string found by its context and name, a translation given.
if (!function_exists('icl_get_string_id')) {
    function icl_get_string_id(string $value, string $context, string $name = ''): int
    {
        WpmlStringFunctions::$strings[] = [$value, $context, $name];

        return WpmlStringFunctions::$ids[$name] ?? 77;
    }
}
if (!function_exists('icl_add_string_translation')) {
    function icl_add_string_translation(int $stringId, string $language, string $value, int $status): int
    {
        WpmlStringFunctions::$translations[] = [$stringId, $language, $value, $status];

        return 1;
    }
}
if (!function_exists('icl_get_string_translations_by_id')) {
    /** @return array<string, array{value: string, status: int}> by language code */
    function icl_get_string_translations_by_id(int $stringId): array
    {
        return WpmlStringFunctions::$finished[$stringId] ?? [];
    }
}
