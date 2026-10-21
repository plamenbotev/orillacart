<?php

declare(strict_types=1);

namespace OrillaCart\Search\Domain;

/**
 * How the index names what products are filtered by. Attributes and their options belong to one product or one
 * attribute set, so the same "Size: M" on two products is matched by name: lowercased, with spaces evened out.
 */
final class Facets
{
    public const string CATEGORY = 'category';
    public const string BRAND = 'brand';
    public const string TAG = 'tag';
    public const string SKU = 'sku';

    private const string ATTRIBUTE_PREFIX = 'attr:';

    /** The facet of an attribute name: "Size" is "attr:size". */
    public static function attribute(string $name): string
    {
        return mb_substr(self::ATTRIBUTE_PREFIX . self::normalize($name), 0, IndexedValue::MAX_FACET_LENGTH);
    }

    /** The value an option name, SKU or term is kept and matched as. */
    public static function value(string $name): string
    {
        return mb_substr(self::normalize($name), 0, IndexedValue::MAX_VALUE_LENGTH);
    }

    private static function normalize(string $name): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $name)));
    }
}
