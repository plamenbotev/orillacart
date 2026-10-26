<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Assistant;

use OrillaCart\Assistant\Application\InputSchemas;
use OrillaCart\Tests\Unit\Assistant\Fixtures\SampleCommand;
use PHPUnit\Framework\TestCase;

/**
 * A route's input as JSON Schema: the placeholders first, then the DTO's fields with their types and rules.
 */
final class InputSchemasTest extends TestCase
{
    public function testTheDtoBecomesASchema(): void
    {
        $schema = InputSchemas::forRoute('/things/{id}/kinds/{kind}', ['id' => '\d+', 'kind' => '[a-z]+'], SampleCommand::class);

        self::assertSame(['type' => 'integer', 'minimum' => 1], self::at($schema, 'properties', 'id'));
        // "kind" is also a field, so the placeholder is given as kind_id.
        self::assertSame(['type' => 'string', 'pattern' => '^(?:[a-z]+)$'], self::at($schema, 'properties', 'kind_id'));
        self::assertSame(['id' => 'id', 'kind' => 'kind_id'], InputSchemas::placeholders('/things/{id}/kinds/{kind}', SampleCommand::class));
        self::assertSame(['id', 'kind_id', 'name', 'amount'], self::at($schema, 'required'));
        self::assertFalse(self::at($schema, 'additionalProperties'));

        self::assertSame(['description' => 'Not empty. At most 100 characters.', 'type' => 'string', 'minLength' => 1, 'maxLength' => 100], self::at($schema, 'properties', 'name'));
        self::assertSame(['description' => 'Use an amount such as 25.50.', 'type' => 'string', 'pattern' => '^\d{1,11}(\.\d{1,4})?$'], self::at($schema, 'properties', 'amount'));
        self::assertSame(['description' => 'The items it covers, if any. At most 5 items.', 'type' => 'array', 'items' => ['$ref' => '#/$defs/SampleLine'], 'maxItems' => 5], self::at($schema, 'properties', 'lines'));
        self::assertSame(['description' => 'Each more than 0.', 'type' => 'array', 'items' => ['type' => 'integer', 'exclusiveMinimum' => 0]], self::at($schema, 'properties', 'ids'));
        self::assertSame(['type' => ['string', 'null']], self::at($schema, 'properties', 'note'));
        self::assertSame(['type' => 'boolean', 'default' => false], self::at($schema, 'properties', 'notify'));
        self::assertSame(['enum' => ['fixed', 'percent'], 'default' => 'fixed'], self::at($schema, 'properties', 'kind'));
        self::assertSame(['description' => 'One of a, b.', 'type' => 'string', 'enum' => ['a', 'b'], 'default' => 'a'], self::at($schema, 'properties', 'letter'));
        self::assertSame(['description' => 'A date, Y-m-d.', 'type' => ['string', 'null'], 'format' => 'date'], self::at($schema, 'properties', 'from'));
        // A pattern with a flag JSON Schema has not is left to the words and the route's own check.
        self::assertArrayNotHasKey('pattern', self::arrayAt($schema, 'properties', 'caseless'));

        self::assertSame([
            'type' => 'object',
            'properties' => [
                'line' => ['description' => 'The line\'s position, from 0. 0 or more.', 'type' => 'integer', 'minimum' => 0],
                'quantity' => ['description' => 'More than 0.', 'type' => 'integer', 'exclusiveMinimum' => 0, 'default' => 1],
            ],
            'required' => ['line'],
            'additionalProperties' => false,
        ], self::at($schema, '$defs', 'SampleLine'));
    }

    public function testARouteWithoutArgumentsTakesAnEmptyObject(): void
    {
        self::assertSame(['type' => 'object', 'additionalProperties' => false], InputSchemas::forRoute('/status', [], null));
    }

    /**
     * The value at a path in decoded JSON; the test fails when the path is not there.
     */
    private static function at(mixed $data, int|string ...$path): mixed
    {
        foreach ($path as $step) {
            self::assertIsArray($data);
            self::assertArrayHasKey($step, $data);
            $data = $data[$step];
        }

        return $data;
    }


    /**
     * The array at a path in decoded JSON.
     *
     * @return array<mixed>
     */
    private static function arrayAt(mixed $data, int|string ...$path): array
    {
        $value = self::at($data, ...$path);
        self::assertIsArray($value);

        return $value;
    }
}
