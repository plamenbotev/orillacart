<?php

declare(strict_types=1);

namespace OrillaCart\Assistant\Infrastructure\WordPress;

/**
 * A tool's JSON Schema as WordPress validates schemas (rest_validate_value_from_schema), for an ability: the
 * $defs written out where they are used, since WordPress follows no $ref, and a numeric exclusiveMinimum or
 * exclusiveMaximum as the minimum or maximum it means, with the older boolean flag beside it; and no input as no
 * arguments.
 */
final class WordPressSchemas
{
    /**
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    public static function of(array $schema): array
    {
        $defs = \is_array($schema['$defs'] ?? null) ? $schema['$defs'] : [];
        unset($schema['$defs']);

        // An ability called with no input gets this, as WordPress applies a schema's default to none.
        return self::rewrite($schema, $defs, 0) + ['default' => []];
    }

    /**
     * @param array<array-key, mixed> $schema
     * @param array<array-key, mixed> $defs
     *
     * @return array<string, mixed>
     */
    private static function rewrite(array $schema, array $defs, int $depth): array
    {
        if (\is_string($schema['$ref'] ?? null)) {
            $name = substr($schema['$ref'], \strlen('#/$defs/'));
            $target = $defs[$name] ?? null;
            // A DTO that holds itself would never end: past a few levels any object is taken.
            $schema = \is_array($target) && $depth < 8 ? array_diff_key($schema, ['$ref' => true]) + $target : ['type' => 'object'];
        }
        foreach (['exclusiveMinimum' => 'minimum', 'exclusiveMaximum' => 'maximum'] as $exclusive => $bound) {
            if (isset($schema[$exclusive]) && (\is_int($schema[$exclusive]) || \is_float($schema[$exclusive]))) {
                $schema[$bound] = $schema[$exclusive];
                $schema[$exclusive] = true;
            }
        }
        $rewritten = [];
        foreach ($schema as $key => $value) {
            if (!\is_string($key)) {
                continue;
            }
            $rewritten[$key] = match (true) {
                \in_array($key, ['properties', 'patternProperties'], true) && \is_array($value) => array_map(
                    static fn(mixed $property): mixed => \is_array($property) ? self::rewrite($property, $defs, $depth + 1) : $property,
                    $value,
                ),
                \in_array($key, ['items', 'additionalProperties'], true) && \is_array($value) => self::rewrite($value, $defs, $depth + 1),
                \in_array($key, ['anyOf', 'oneOf', 'allOf'], true) && \is_array($value) => array_values(array_map(
                    static fn(mixed $option): mixed => \is_array($option) ? self::rewrite($option, $defs, $depth + 1) : $option,
                    $value,
                )),
                default => $value,
            };
        }

        return $rewritten;
    }
}
