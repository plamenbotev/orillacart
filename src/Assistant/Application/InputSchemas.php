<?php

declare(strict_types=1);

namespace OrillaCart\Assistant\Application;

use OrillaCart\Shared\Http\Routing\InputField;
use OrillaCart\Shared\Http\Routing\RouteInputs;
use OrillaCart\Shared\Http\Routing\RuleWords;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A route's input as JSON Schema 2020-12, for the assistant's tools: the path's placeholders, then the fields of
 * the DTO the action maps the body (or, for a read, the query) to. Types come from the DTO's constructor and
 * docblock, limits from its validation rules; a rule JSON Schema cannot say is left to the words of the field's
 * description, and the route's own validation still decides.
 */
final class InputSchemas
{
    /** The arguments a tool sends a file with, in place of a multipart upload. */
    public const array FILE = [
        'file_name' => ['description' => 'The file\'s name, with its extension: "products.csv".', 'type' => 'string', 'minLength' => 1, 'maxLength' => 200],
        'file_base64' => ['description' => 'The file\'s contents, base64-encoded, up to 20 MB of base64 (about 15 MB of file). Give this or file_url; a larger file only as file_url.', 'type' => 'string', 'contentEncoding' => 'base64'],
        'file_url' => ['description' => 'An http(s) address the shop fetches the file from. Give this or file_base64.', 'type' => 'string', 'format' => 'uri'],
    ];

    /**
     * The argument each of the path's placeholders is given as: its own name, or "{name}_id" when the DTO has a
     * field of that name ("address" in PUT /customers/{id}/addresses/{address}).
     *
     * @param class-string|null $dto
     *
     * @return array<string, string> argument name by placeholder
     */
    public static function placeholders(string $path, ?string $dto): array
    {
        $fields = $dto === null ? [] : array_map(static fn(InputField $field): string => $field->name, RouteInputs::fields($dto));
        preg_match_all('/\{([A-Za-z_][A-Za-z0-9_]*)\}/', $path, $names);
        $arguments = [];
        foreach ($names[1] as $name) {
            $argument = $name;
            while (\in_array($argument, $fields, true)) {
                $argument .= '_id';
            }
            $arguments[$name] = $argument;
        }

        return $arguments;
    }

    /**
     * @param array<string, string> $requirements regular expression per placeholder
     * @param class-string|null     $dto          the body's DTO, or the query's for a read
     * @param bool                  $file         the route takes an uploaded file, which a tool sends as base64 or an address
     *
     * @return array<string, mixed>
     */
    public static function forRoute(string $path, array $requirements, ?string $dto, bool $file = false): array
    {
        $defs = [];
        $properties = [];
        $required = [];
        foreach (self::placeholders($path, $dto) as $name => $argument) {
            $pattern = $requirements[$name] ?? '[^/]+';
            $properties[$argument] = $pattern === '\d+'
                ? ['type' => 'integer', 'minimum' => 1]
                : ['type' => 'string', 'pattern' => '^(?:' . $pattern . ')$'];
            $required[] = $argument;
        }
        if ($dto !== null) {
            $object = self::object($dto, $defs);
            foreach ($object['properties'] ?? [] as $name => $property) {
                $properties[$name] = $property;
            }
            array_push($required, ...$object['required'] ?? []);
        }
        if ($file) {
            $properties += self::FILE;
            $required[] = 'file_name';
        }
        // No properties: the spec's recommended schema for a tool without arguments, and nothing that encodes as [].
        $schema = $properties === [] ? ['type' => 'object'] : ['type' => 'object', 'properties' => $properties];
        if ($required !== []) {
            $schema['required'] = $required;
        }
        $schema['additionalProperties'] = false;
        if ($defs !== []) {
            ksort($defs);
            $schema['$defs'] = $defs;
        }

        return $schema;
    }

    /**
     * The input of a route whose action reads its body whole: the placeholders, and the body as one object.
     *
     * @param array<string, string> $requirements
     * @param array<string, string> $body         [argument => what it is]
     *
     * @return array<string, mixed>
     */
    public static function wholeBody(string $path, array $requirements, array $body): array
    {
        $schema = self::forRoute($path, $requirements, null);
        $name = (string) array_key_first($body);
        $properties = \is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        $properties[$name] = ['description' => $body[$name], 'type' => 'object'];
        $required = \is_array($schema['required'] ?? null) ? $schema['required'] : [];
        $required[] = $name;

        return ['type' => 'object', 'properties' => $properties, 'required' => $required, 'additionalProperties' => false];
    }

    /**
     * @param class-string                        $dto
     * @param array<string, array<string, mixed>> $defs
     *
     * @return array{type: 'object', properties?: array<string, array<string, mixed>>, required?: list<string>, additionalProperties: false}
     */
    private static function object(string $dto, array &$defs): array
    {
        $properties = [];
        $required = [];
        foreach (RouteInputs::fields($dto) as $field) {
            $properties[$field->name] = self::field($field, $dto, $defs);
            if ($field->required) {
                $required[] = $field->name;
            }
        }
        $object = $properties === [] ? ['type' => 'object'] : ['type' => 'object', 'properties' => $properties];
        if ($required !== []) {
            $object['required'] = $required;
        }
        $object['additionalProperties'] = false;

        return $object;
    }

    /**
     * @param class-string                        $dto
     * @param array<string, array<string, mixed>> $defs
     *
     * @return array<string, mixed>
     */
    private static function field(InputField $field, string $dto, array &$defs): array
    {
        [$schema, $nullable] = self::type($field->type, $dto, $defs);
        $words = [];
        foreach ($field->rules as $attribute) {
            $rule = $attribute->newInstance();
            $schema = self::rule($rule, $schema);
            $message = property_exists($rule, 'message') && \is_string($rule->message) && $rule instanceof Assert\Regex ? $rule->message : null;
            $word = $message !== null && !str_starts_with($message, 'This value') ? $message : RuleWords::of($attribute);
            if ($word !== '') {
                $words[] = $word;
            }
        }
        if ($nullable) {
            $schema = isset($schema['$ref']) ? ['anyOf' => [$schema, ['type' => 'null']]] : self::orNull($schema);
        }
        $description = self::sentences([$field->description, ...$words]);
        if ($description !== '') {
            $schema = ['description' => $description] + $schema;
        }
        if (!$field->required && $field->default !== null && $field->default !== []) {
            $default = $field->default instanceof \BackedEnum ? $field->default->value : $field->default;
            if (\is_scalar($default) || \is_array($default)) {
                $schema['default'] = $default;
            }
        }

        return $schema;
    }

    /**
     * Words as sentences, each capitalised and ended once: "the items it covers, if any", "at most 500 items"
     * read "The items it covers, if any. At most 500 items."
     *
     * @param list<string> $parts
     */
    private static function sentences(array $parts): string
    {
        $sentences = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part !== '') {
                $sentences[] = ucfirst(rtrim($part, '.')) . '.';
            }
        }

        return implode(' ', $sentences);
    }

    /**
     * The schema of a PHP or docblock type, and whether null is allowed too.
     *
     * @param class-string                        $dto
     * @param array<string, array<string, mixed>> $defs
     *
     * @return array{array<string, mixed>, bool}
     */
    private static function type(string $type, string $dto, array &$defs): array
    {
        $type = trim($type);
        $nullable = false;
        if (str_starts_with($type, '?')) {
            $nullable = true;
            $type = substr($type, 1);
        }
        $parts = self::union($type);
        if (\in_array('null', $parts, true)) {
            $nullable = true;
            $parts = array_values(array_filter($parts, static fn(string $part): bool => $part !== 'null'));
        }
        if (\count($parts) > 1) {
            return [['anyOf' => array_map(static fn(string $part): array => self::type($part, $dto, $defs)[0], $parts)], $nullable];
        }
        $type = $parts[0] ?? 'mixed';

        if (preg_match('/^(non-empty-)?list<(.+)>$/', $type, $match) === 1) {
            $schema = ['type' => 'array', 'items' => self::type($match[2], $dto, $defs)[0]];
            if ($match[1] !== '') {
                $schema['minItems'] = 1;
            }

            return [$schema, $nullable];
        }
        if (preg_match('/^array<(?:string|non-empty-string),\s*(.+)>$/', $type, $match) === 1) {
            $values = self::type($match[1], $dto, $defs)[0];

            return [$values === [] ? ['type' => 'object'] : ['type' => 'object', 'additionalProperties' => $values], $nullable];
        }
        if (preg_match('/^array<int,\s*(.+)>$/', $type, $match) === 1) {
            return [['type' => 'array', 'items' => self::type($match[1], $dto, $defs)[0]], $nullable];
        }

        $schema = match (strtolower(ltrim($type, '\\'))) {
            'int', 'positive-int', 'non-negative-int' => ['type' => 'integer'],
            'float' => ['type' => 'number'],
            'string', 'non-empty-string', 'numeric-string' => ['type' => 'string'],
            'bool' => ['type' => 'boolean'],
            'true' => ['const' => true],
            'false' => ['const' => false],
            'array' => ['type' => ['object', 'array']],
            'mixed' => [],
            'datetimeimmutable', 'datetimeinterface' => ['type' => 'string', 'format' => 'date-time'],
            default => null,
        };
        if ($schema !== null) {
            return [$schema, $nullable];
        }

        $class = self::resolve($type, $dto);
        if ($class !== null && enum_exists($class) && is_subclass_of($class, \BackedEnum::class)) {
            return [['enum' => array_map(static fn(\BackedEnum $case): int|string => $case->value, $class::cases())], $nullable];
        }
        $nested = RouteInputs::classOf($type, $dto);
        if ($nested !== null) {
            $name = new \ReflectionClass($nested)->getShortName();
            if (!isset($defs[$name])) {
                $defs[$name] = [];
                $defs[$name] = self::object($nested, $defs);
            }

            return [['$ref' => '#/$defs/' . $name], $nullable];
        }

        throw new \LogicException(\sprintf('%s has a field of type "%s" the assistant cannot describe.', $dto, $type));
    }

    /**
     * A constraint as JSON Schema keywords, on the field's schema (or on a list's items, for All).
     *
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    private static function rule(object $rule, array $schema): array
    {
        $type = $schema['type'] ?? null;
        switch (true) {
            case $rule instanceof Assert\NotBlank:
                if ($type === 'string') {
                    $schema['minLength'] = max(1, \is_int($schema['minLength'] ?? null) ? $schema['minLength'] : 1);
                } elseif ($type === 'array') {
                    $schema['minItems'] = max(1, \is_int($schema['minItems'] ?? null) ? $schema['minItems'] : 1);
                }
                break;
            case $rule instanceof Assert\Length:
                if ($rule->min !== null && $rule->min > 0) {
                    $schema['minLength'] = $rule->min;
                }
                if ($rule->max !== null) {
                    $schema['maxLength'] = $rule->max;
                }
                break;
            case $rule instanceof Assert\Count:
                if ($rule->min !== null && $rule->min > 0) {
                    $schema['minItems'] = $rule->min;
                }
                if ($rule->max !== null) {
                    $schema['maxItems'] = $rule->max;
                }
                break;
            case $rule instanceof Assert\Range:
                if (is_numeric($rule->min)) {
                    $schema['minimum'] = $rule->min + 0;
                }
                if (is_numeric($rule->max)) {
                    $schema['maximum'] = $rule->max + 0;
                }
                break;
            case $rule instanceof Assert\Positive:
                $schema['exclusiveMinimum'] = 0;
                break;
            case $rule instanceof Assert\PositiveOrZero:
                $schema['minimum'] = 0;
                break;
            case $rule instanceof Assert\Email:
                $schema['format'] = 'email';
                break;
            case $rule instanceof Assert\Url:
                $schema['format'] = 'uri';
                break;
            case $rule instanceof Assert\Date:
                $schema['format'] = 'date';
                break;
            case $rule instanceof Assert\DateTime:
                if (\in_array($rule->format, [\DATE_ATOM, \DATE_RFC3339], true)) {
                    $schema['format'] = 'date-time';
                }
                break;
            case $rule instanceof Assert\Choice:
                $choices = \is_array($rule->choices) ? $rule->choices : (\is_callable($rule->callback) ? ($rule->callback)() : null);
                if (\is_array($choices)) {
                    $choices = array_values(array_map(static fn(mixed $choice): mixed => $choice instanceof \BackedEnum ? $choice->value : $choice, $choices));
                    if ($rule->multiple && $type === 'array') {
                        $schema['items'] = ['enum' => $choices];
                    } else {
                        $schema['enum'] = $choices;
                    }
                }
                break;
            case $rule instanceof Assert\Regex:
                $pattern = self::pattern($rule);
                if ($pattern !== null) {
                    $schema['pattern'] = $pattern;
                }
                break;
            case $rule instanceof Assert\All:
                if ($type === 'array' && \is_array($schema['items'] ?? null)) {
                    $items = self::keyed($schema['items']);
                    foreach (\is_array($rule->constraints) ? $rule->constraints : [$rule->constraints] as $inner) {
                        if (\is_object($inner)) {
                            $items = self::rule($inner, $items);
                        }
                    }
                    $schema['items'] = $items;
                }
                break;
        }

        return $schema;
    }

    /** A PCRE pattern as ECMA-262 for JSON Schema, when it means the same there; null when it may not. */
    private static function pattern(Assert\Regex $rule): ?string
    {
        if (!$rule->match || !\is_string($rule->pattern)) {
            return null;
        }
        if (preg_match('/^(.)(.*)\1([a-zA-Z]*)$/s', $rule->pattern, $parts) !== 1 || str_replace('u', '', $parts[3]) !== '') {
            return null;
        }
        $body = $parts[2];
        // PCRE only: anchors \A \z \Z, possessive and atomic groups, inline flags, \h; ECMA reads them otherwise.
        if (preg_match('/\\\\[AzZhHKR]|\(\?[^:=!<]|[*+?}]\+/', $body) === 1) {
            return null;
        }

        return $body;
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    private static function orNull(array $schema): array
    {
        if (isset($schema['enum']) && \is_array($schema['enum'])) {
            $schema['enum'][] = null;
        }
        if (!isset($schema['type'])) {
            return $schema === [] ? [] : ['anyOf' => [$schema, ['type' => 'null']]];
        }
        $types = array_values(array_filter((array) $schema['type'], \is_string(...)));
        $types[] = 'null';
        $schema['type'] = array_values(array_unique($types));

        return $schema;
    }

    /**
     * The parts of a union, outside brackets: "int|list<int|string>|null".
     *
     * @return list<string>
     */
    private static function union(string $type): array
    {
        $parts = [];
        $depth = 0;
        $current = '';
        foreach (str_split($type) as $char) {
            if ($char === '<') {
                ++$depth;
            } elseif ($char === '>') {
                --$depth;
            }
            if ($char === '|' && $depth === 0) {
                $parts[] = trim($current);
                $current = '';
                continue;
            }
            $current .= $char;
        }
        $parts[] = trim($current);

        return array_values(array_filter($parts, static fn(string $part): bool => $part !== ''));
    }

    /**
     * The schema keywords of a decoded schema, which are all named.
     *
     * @param array<mixed> $schema
     *
     * @return array<string, mixed>
     */
    private static function keyed(array $schema): array
    {
        return array_filter($schema, \is_string(...), ARRAY_FILTER_USE_KEY);
    }

    /**
     * A class or enum named in a DTO, from its namespace and imports.
     *
     * @param class-string $dto
     */
    private static function resolve(string $type, string $dto): ?string
    {
        $type = ltrim($type, '\\');
        if (preg_match('/^[A-Z][\w\\\\]*$/', $type) !== 1) {
            return null;
        }
        if (!str_contains($type, '\\')) {
            $code = (string) file_get_contents((string) new \ReflectionClass($dto)->getFileName());
            $type = preg_match('/^use ([\w\\\\]+\\\\' . $type . ');$/m', $code, $use) === 1 ? $use[1] : (new \ReflectionClass($dto)->getNamespaceName() . '\\' . $type);
        }

        return class_exists($type) || enum_exists($type) ? $type : null;
    }
}
