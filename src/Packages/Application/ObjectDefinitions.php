<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Application;

use OrillaCart\Packages\Application\Dto\ObjectQuery;
use OrillaCart\Packages\Application\Dto\PackageManifest;
use OrillaCart\Scripting\Domain\Ops;
use OrillaCart\Scripting\Domain\ScriptError;

/**
 * What an extension declared about its objects and fields, read from its manifest: the fields of each object
 * kind with their kinds, which are indexed, and the fields it adds to the shop's records. Checks what a script
 * writes against them, and turns a script's query map into an ObjectQuery that only names indexed fields.
 */
final class ObjectDefinitions
{
    public const array OWNERS = ['product', 'order', 'customer'];
    public const array FIELD_KINDS = ['text', 'number', 'money', 'boolean', 'date', 'datetime', 'choice', 'list', 'map', 'product', 'order', 'customer', 'object'];

    /** @var array<string, array<string, array<string, array{string, array<string, mixed>}>>> extension → type → field → [kind, options] */
    private array $objects = [];
    /** @var array<string, array<string, list<string>>> extension → type → indexed fields */
    private array $indexed = [];
    /** @var array<string, array<string, array<string, array{string, array<string, mixed>}>>> extension → owner → field → [kind, options] */
    private array $fields = [];
    /** @var array<string, true> */
    private array $read = [];

    public function __construct(private readonly PackageService $packages) {}

    /**
     * The declared fields of an object kind.
     *
     * @return array<string, array{string, array<string, mixed>}>
     */
    public function object(string $extension, string $type): array
    {
        $this->read($extension);

        return $this->objects[$extension][$type] ?? throw new ScriptError(\sprintf('The extension declares no object "%s".', $type));
    }

    /** @return list<string> */
    public function indexed(string $extension, string $type): array
    {
        $this->object($extension, $type);

        return $this->indexed[$extension][$type] ?? [];
    }

    /**
     * The fields declared on one of the shop's kinds.
     *
     * @return array<string, array{string, array<string, mixed>}>
     */
    public function fields(string $extension, string $owner): array
    {
        if (!\in_array($owner, self::OWNERS, true)) {
            throw new ScriptError(\sprintf('Fields go on a product, an order or a customer, not "%s".', $owner));
        }
        $this->read($extension);

        return $this->fields[$extension][$owner] ?? [];
    }

    /**
     * The data a script gives, checked against the declaration: only declared fields, each of its kind.
     *
     * @param array<string, array{string, array<string, mixed>}> $declared
     * @param array<mixed>                                        $data
     *
     * @return array<string, mixed>
     */
    public static function check(array $declared, array $data, bool $whole): array
    {
        $checked = [];
        foreach ($data as $field => $value) {
            $field = (string) $field;
            if (!isset($declared[$field])) {
                throw new ScriptError(\sprintf('No field "%s" is declared; the fields are %s.', $field, $declared === [] ? 'none' : implode(', ', array_keys($declared))));
            }
            $checked[$field] = self::value($field, $declared[$field][0], $declared[$field][1], $value);
        }
        if ($whole) {
            foreach ($declared as $field => [$kind, $options]) {
                if (!\array_key_exists($field, $checked)) {
                    if (($options['required'] ?? false) === true) {
                        throw new ScriptError(\sprintf('The field "%s" is required.', $field));
                    }
                    $checked[$field] = $options['default'] ?? null;
                }
            }
        }

        return $checked;
    }

    /**
     * A script's query map as an ObjectQuery over indexed fields.
     *
     * @param list<string> $indexed
     */
    public static function query(mixed $map, array $indexed): ObjectQuery
    {
        $map = \is_array($map) ? $map : [];
        $where = [];
        foreach (\is_array($map['where'] ?? null) ? $map['where'] : [] as $key => $value) {
            $parts = explode(' ', trim((string) $key), 2);
            $field = $parts[0];
            $operator = $parts[1] ?? '=';
            if (!\in_array($operator, ['=', '!=', '<', '<=', '>', '>='], true)) {
                throw new ScriptError(\sprintf('"%s" is not a comparison; use =, !=, <, <=, > or >=.', $operator));
            }
            if ($field !== 'id' && !\in_array($field, $indexed, true) && !\in_array($field, self::OWNERS, true)) {
                throw new ScriptError(\sprintf('"%s" is not indexed: a query filters on the fields the declaration indexes (%s), the references, or id.', $field, $indexed === [] ? 'none' : implode(', ', $indexed)));
            }
            $where[] = [$field, $operator, Ops::plain($value)];
        }
        $orderBy = Ops::text($map['order_by'] ?? 'id');
        $descending = str_starts_with($orderBy, '-');
        $orderBy = ltrim($orderBy, '-');
        if (!\in_array($orderBy, ['id', 'created_at', 'updated_at', ...$indexed], true)) {
            throw new ScriptError(\sprintf('"%s" is not indexed: a query orders by an indexed field, id, created_at or updated_at.', $orderBy));
        }
        $limit = (int) Ops::text($map['limit'] ?? 50);
        if ($limit < 1 || $limit > ObjectQuery::MOST) {
            throw new ScriptError(\sprintf('A query takes 1 to %d records.', ObjectQuery::MOST));
        }
        $after = isset($map['after']) ? (int) Ops::text($map['after']) : null;

        return new ObjectQuery($where, $orderBy, $descending, $limit, $after);
    }

    private function read(string $extension): void
    {
        if (isset($this->read[$extension])) {
            return;
        }
        $this->read[$extension] = true;
        foreach ($this->packages->loaded() as $loaded) {
            if ($loaded->package->slug() === $extension) {
                $this->apply($extension, $loaded->manifest);
            }
        }
    }

    /**
     * Takes the declarations from this manifest for the extension, whatever is enabled: an extension's tests run
     * over the files as edited, draft or not. forget() afterwards reads the enabled declarations again.
     */
    public function use(string $extension, PackageManifest $manifest): void
    {
        $this->forget($extension);
        $this->read[$extension] = true;
        $this->apply($extension, $manifest);
    }

    public function forget(string $extension): void
    {
        unset($this->read[$extension], $this->objects[$extension], $this->indexed[$extension], $this->fields[$extension]);
    }

    private function apply(string $extension, PackageManifest $manifest): void
    {
        foreach ($manifest->ofKind('object') as $declaration) {
            $type = (string) $declaration['name'];
            $this->objects[$extension][$type] = self::declaredFields($declaration['props']['fields'] ?? []);
            $index = $declaration['props']['index'] ?? [];
            $this->indexed[$extension][$type] = array_values(array_filter(array_map(self::word(...), \is_array($index) ? $index : []), static fn(string $f): bool => $f !== ''));
        }
        foreach ($manifest->ofKind('fields') as $declaration) {
            $owner = $declaration['words'][1] ?? '';
            if (($declaration['words'][0] ?? '') === 'on' && \in_array($owner, self::OWNERS, true)) {
                $this->fields[$extension][$owner] = self::declaredFields($declaration['props']);
            }
        }
    }

    /** A declared word (`state`, `read`) or text as a string. */
    private static function word(mixed $value): string
    {
        if (\is_array($value)) {
            $spec = $value['$spec'] ?? '';

            return \is_string($spec) ? $spec : '';
        }

        return Ops::text($value);
    }

    /**
     * @return array<string, array{string, array<string, mixed>}>
     */
    private static function declaredFields(mixed $props): array
    {
        $fields = [];
        foreach (\is_array($props) ? $props : [] as $name => $spec) {
            $kind = \is_array($spec) ? ($spec['$spec'] ?? 'text') : 'text';
            if (!\is_string($kind) || !\in_array($kind, self::FIELD_KINDS, true)) {
                continue;
            }
            $options = [];
            foreach (\is_array($spec) ? $spec : [] as $option => $value) {
                if ($option !== '$spec') {
                    $options[(string) $option] = $value;
                }
            }
            $fields[(string) $name] = [$kind, $options];
        }

        return $fields;
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function value(string $field, string $kind, array $options, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }
        $wrong = static fn(string $what): ScriptError => new ScriptError(\sprintf('The field "%s" takes %s, not %s.', $field, $what, Ops::kind($value)));
        switch ($kind) {
            case 'text':
                $text = Ops::text($value);
                $longest = isset($options['longest']) ? (int) Ops::text($options['longest']) : 2000;

                return mb_strlen($text) > $longest ? throw new ScriptError(\sprintf('The field "%s" takes up to %d characters.', $field, $longest)) : $text;
            case 'number':
                return \is_int($value) || \is_float($value) ? $value : (is_numeric(Ops::text($value)) ? (float) Ops::text($value) : throw $wrong('a number'));
            case 'money':
                return \OrillaCart\Packages\Application\Values::amount($value);
            case 'boolean':
                return Ops::truthy($value);
            case 'date':
            case 'datetime':
                $text = Ops::text($value);
                try {
                    $moment = new \DateTimeImmutable($text);
                } catch (\Exception) {
                    throw $wrong('a date');
                }

                return $kind === 'date' ? $moment->format('Y-m-d') : $moment->format(\DATE_ATOM);
            case 'choice':
                $text = Ops::text($value);
                $of = array_map(self::word(...), \is_array($options['of'] ?? null) ? $options['of'] : []);

                return \in_array($text, $of, true) ? $text : throw new ScriptError(\sprintf('The field "%s" takes one of %s.', $field, implode(', ', $of)));
            case 'list':
                return \is_array($value) && array_is_list($value) ? Ops::plain($value) : throw $wrong('a list');
            case 'map':
                return \is_array($value) ? Ops::plain($value) : throw $wrong('a map');
            case 'product':
            case 'order':
            case 'customer':
            case 'object':
                $id = \is_array($value) ? ($value['id'] ?? ($value['number'] ?? null)) : $value;
                $id = (int) Ops::text($id);

                return $id > 0 ? ['$ref' => $kind, 'id' => $id] : throw $wrong('an id');
        }

        return $value;
    }
}
