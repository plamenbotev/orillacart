<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Assistant;

use OrillaCart\Assistant\Infrastructure\WordPress\WordPressSchemas;
use PHPUnit\Framework\TestCase;

/**
 * A tool's schema as WordPress validates an ability's input: no $ref, the older exclusive bounds, and a default
 * for no input.
 */
final class WordPressSchemasTest extends TestCase
{
    public function testDefsAreWrittenOutAndExclusiveBoundsAreTheOldKind(): void
    {
        $schema = WordPressSchemas::of([
            'type' => 'object',
            'properties' => [
                'lines' => ['type' => 'array', 'items' => ['$ref' => '#/$defs/Line']],
                'address' => ['anyOf' => [['$ref' => '#/$defs/Address'], ['type' => 'null']]],
            ],
            'additionalProperties' => false,
            '$defs' => [
                'Line' => ['type' => 'object', 'properties' => ['quantity' => ['type' => 'integer', 'exclusiveMinimum' => 0]]],
                'Address' => ['type' => 'object', 'properties' => ['city' => ['type' => 'string']]],
            ],
        ]);

        self::assertSame([
            'type' => 'object',
            'properties' => [
                'lines' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['quantity' => ['type' => 'integer', 'exclusiveMinimum' => true, 'minimum' => 0]]]],
                'address' => ['anyOf' => [['type' => 'object', 'properties' => ['city' => ['type' => 'string']]], ['type' => 'null']]],
            ],
            'additionalProperties' => false,
            'default' => [],
        ], $schema);
    }
}
