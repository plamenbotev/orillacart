<?php

declare(strict_types=1);

namespace OrillaCart\Shared\Http\Routing;

/**
 * What a route's action says about itself, read from its code: what it does (the first paragraph of its
 * docblock), and the DTOs it maps the body and the query to, with their fields. The REST documentation and the
 * assistant's tools both read routes through this, so they say the same.
 */
final class RouteInputs
{
    private const string SNAKE = '/(?<!^)[A-Z]/';

    /** The first paragraph of the class's docblock, on one line. */
    public static function summary(string $class): string
    {
        if (!class_exists($class)) {
            return '';
        }
        $doc = (string) new \ReflectionClass($class)->getDocComment();
        $lines = [];
        foreach (explode("\n", $doc) as $line) {
            $line = trim(preg_replace(['#^\s*(/\*\*|\*/|\*)\s?#', '#\s*\*/$#'], '', $line) ?? '');
            if ($line === '' && $lines !== []) {
                break;
            }
            if ($line !== '' && !str_starts_with($line, '@')) {
                $lines[] = $line;
            }
        }

        return implode(' ', $lines);
    }

    /**
     * The DTOs the action maps the body and the query to, from its code: $this->input->body($request, X::class).
     *
     * @return array{?class-string, ?class-string}
     */
    public static function dtos(string $action): array
    {
        if (!class_exists($action)) {
            return [null, null];
        }
        $file = new \ReflectionClass($action)->getFileName();
        $code = \is_string($file) ? (string) file_get_contents($file) : '';
        $class = static function (string $kind) use ($code): ?string {
            if (preg_match('/->' . $kind . '\(\$request, (\w+)::class\)/', $code, $match) !== 1) {
                return null;
            }
            $name = preg_match('/^use ([\w\\\\]+\\\\' . $match[1] . ');$/m', $code, $use) === 1 ? $use[1] : null;

            return $name !== null && class_exists($name) ? $name : null;
        };

        return [$class('body'), $class('query')];
    }

    /**
     * The fields of a DTO, in its constructor's order.
     *
     * @param class-string $dto
     *
     * @return list<InputField>
     */
    public static function fields(string $dto): array
    {
        $constructor = new \ReflectionClass($dto)->getConstructor();
        if ($constructor === null) {
            return [];
        }
        $documented = self::params((string) $constructor->getDocComment());
        $fields = [];
        foreach ($constructor->getParameters() as $parameter) {
            $name = $parameter->getName();
            $hasDefault = $parameter->isDefaultValueAvailable();
            $fields[] = new InputField(
                strtolower((string) preg_replace(self::SNAKE, '_$0', $name)),
                $name,
                $documented[$name]['type'] ?? (string) $parameter->getType(),
                !$hasDefault,
                $hasDefault ? $parameter->getDefaultValue() : null,
                // A promoted parameter's rules target the property: only from there can they be instantiated.
                $parameter->isPromoted() ? new \ReflectionProperty($dto, $name)->getAttributes() : $parameter->getAttributes(),
                $documented[$name]['description'] ?? '',
            );
        }

        return $fields;
    }

    /**
     * The class a type names, or the class of a list's items: "?AddressData", "list<RefundLine>", resolved from
     * the DTO's namespace and imports. Null for PHP's own types and for enums.
     *
     * @param class-string $dto the DTO the type was read from
     *
     * @return class-string|null
     */
    public static function classOf(string $type, string $dto): ?string
    {
        $type = ltrim($type, '?');
        if (preg_match('/^(?:non-empty-)?list<\??([A-Za-z_\\\\]\w*(?:\\\\\w+)*)>(?:\|null)?$/', $type, $match) === 1) {
            $type = $match[1];
        }
        $type = (string) preg_replace('/\|null$/', '', $type);
        if (preg_match('/^\\\\?[A-Z][\w\\\\]*$/', $type) !== 1) {
            return null;
        }
        $type = ltrim($type, '\\');
        if (!str_contains($type, '\\')) {
            $code = (string) file_get_contents((string) new \ReflectionClass($dto)->getFileName());
            $type = preg_match('/^use ([\w\\\\]+\\\\' . $type . ');$/m', $code, $use) === 1 ? $use[1] : (new \ReflectionClass($dto)->getNamespaceName() . '\\' . $type);
        }

        return str_starts_with($type, 'OrillaCart\\') && class_exists($type) && !enum_exists($type) ? $type : null;
    }

    /**
     * The shop's classes the DTO's fields are of, or lists of.
     *
     * @param class-string $dto
     *
     * @return list<class-string>
     */
    public static function nested(string $dto): array
    {
        $constructor = new \ReflectionClass($dto)->getConstructor();
        if ($constructor === null) {
            return [];
        }
        $documented = self::params((string) $constructor->getDocComment());
        $nested = [];
        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();
            if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                $name = $type->getName();
                if (str_starts_with($name, 'OrillaCart\\') && class_exists($name) && !enum_exists($name)) {
                    $nested[] = $name;
                }
            }
            $listed = $documented[$parameter->getName()]['type'] ?? '';
            if (preg_match('/list<\??[A-Z]\w+>/', $listed) === 1) {
                $class = self::classOf($listed, $dto);
                if ($class !== null) {
                    $nested[] = $class;
                }
            }
        }

        return $nested;
    }

    /**
     * The @param types and words of a docblock, by parameter name. A description may run on over the lines below.
     *
     * @return array<string, array{type: string, description: string}>
     */
    private static function params(string $doc): array
    {
        $params = [];
        $current = null;
        foreach (explode("\n", $doc) as $line) {
            $line = trim((string) preg_replace(['#^\s*(/\*\*|\*/|\*)\s?#', '#\s*\*/$#'], '', $line));
            if (preg_match('/^@param\s+(\S+)\s+\$(\w+)\s*(.*)$/', $line, $match) === 1) {
                $current = $match[2];
                $params[$current] = ['type' => $match[1], 'description' => trim($match[3])];
                continue;
            }
            if ($line === '' || str_starts_with($line, '@')) {
                $current = null;
                continue;
            }
            if ($current !== null) {
                $params[$current] = ['type' => $params[$current]['type'], 'description' => trim($params[$current]['description'] . ' ' . $line)];
            }
        }

        return $params;
    }
}
