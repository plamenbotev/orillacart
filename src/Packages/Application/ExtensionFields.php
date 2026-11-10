<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Application;

use OrillaCart\Customers\Application\Accounts;
use OrillaCart\Customers\Domain\CustomerNotFound;
use OrillaCart\Packages\Application\Dto\FieldSection;
use OrillaCart\Packages\Application\Dto\FieldSpec;
use OrillaCart\Packages\Domain\PackageRuleViolation;
use OrillaCart\Scripting\Domain\Ops;
use OrillaCart\Scripting\Domain\ScriptError;

/**
 * The fields the enabled extensions declare on the shop's products, orders and customers (`fields on product {
 * lead_time: number { label: "Lead time" } }`), for the shop's own screens: what is declared, as the admin
 * builds its "Extensions" section from; the values of a record by extension, as the REST views show them under
 * `extensions.<id>`; and a save from the admin, checked against the declaration as a script's `fields.set` is.
 */
final readonly class ExtensionFields
{
    /** The REST path's plural and the owner kind it names. */
    public const array KINDS = ['products' => 'product', 'orders' => 'order', 'customers' => 'customer'];

    public function __construct(private PackageService $packages, private ObjectStore $store, private Accounts $accounts) {}

    /**
     * What the enabled extensions declare on one of the shop's kinds, one section per extension that declares
     * anything.
     *
     * @return list<FieldSection>
     */
    public function declared(string $owner): array
    {
        $sections = [];
        foreach ($this->packages->loaded() as $loaded) {
            $fields = [];
            foreach ($loaded->manifest->ofKind('fields') as $declaration) {
                if (($declaration['words'][0] ?? '') !== 'on' || ($declaration['words'][1] ?? '') !== $owner) {
                    continue;
                }
                foreach (ObjectDefinitions::declaredFields($declaration['props']) as $name => [$kind, $options]) {
                    $fields[] = self::spec($name, $kind, $options, $loaded->word(...));
                }
            }
            if ($fields !== []) {
                $sections[] = new FieldSection($loaded->package->slug(), $loaded->word($loaded->package->name()), $fields);
            }
        }

        return $sections;
    }

    public function section(string $owner, string $extension): ?FieldSection
    {
        foreach ($this->declared($owner) as $section) {
            if ($section->extension === $extension) {
                return $section;
            }
        }

        return null;
    }

    /**
     * The extensions' fields on one record, by extension: every declared field, its default where nothing was
     * set. Empty when no extension declares fields on the kind.
     *
     * @return array<string, array<string, mixed>>
     */
    public function of(string $owner, int $id): array
    {
        return $this->ofMany($owner, [$id])[$id] ?? [];
    }

    /**
     * The same for many records, read in one query per extension: for a list or an export.
     *
     * @param list<int> $ids
     *
     * @return array<int, array<string, array<string, mixed>>> by record id, then extension, then field
     */
    public function ofMany(string $owner, array $ids): array
    {
        $values = array_fill_keys($ids, []);
        foreach ($this->declared($owner) as $section) {
            $stored = $ids === [] ? [] : $this->store->fieldsOfMany($section->extension, $owner, $ids);
            foreach ($ids as $id) {
                $fields = [];
                foreach ($section->fields as $field) {
                    $fields[$field->name] = \array_key_exists($field->name, $stored[$id] ?? []) ? $stored[$id][$field->name] : $field->default;
                }
                $values[$id][$section->extension] = $fields;
            }
        }

        return $values;
    }

    /**
     * Writes the fields given, checked as a script's `fields.set` is; null clears one. A field the declaration
     * marks readonly is the scripts' to write. A customer's fields are written on the shop's own customers only:
     * not on its staff, and on a network not on another site's users.
     *
     * @param array<string, mixed> $values by field
     *
     * @return array<string, mixed> the extension's fields on the record afterwards
     *
     * @throws PackageRuleViolation
     * @throws CustomerNotFound
     */
    public function save(string $owner, int $id, string $extension, array $values): array
    {
        if ($owner === 'customer' && !$this->accounts->isShopCustomer($id)) {
            throw new CustomerNotFound($id);
        }
        $section = $this->section($owner, $extension) ?? throw new PackageRuleViolation(\sprintf('No enabled extension "%s" declares fields on %s.', $extension, $owner), 'orillacart_unknown_extension_fields');
        $checked = [];
        foreach ($values as $name => $value) {
            $field = $section->field((string) $name) ?? throw new PackageRuleViolation(\sprintf('The extension "%s" declares no field "%s" on %s.', $extension, $name, $owner), 'orillacart_unknown_extension_field');
            if ($field->readonly) {
                throw new PackageRuleViolation(\sprintf('The field "%s" is written by the extension\'s scripts only.', $field->label), 'orillacart_readonly_extension_field');
            }
            try {
                $checked[$field->name] = ObjectDefinitions::check($section->declared(), [$field->name => $value], false)[$field->name];
            } catch (ScriptError $e) {
                throw new PackageRuleViolation($e->getMessage(), 'orillacart_invalid_extension_field', $e);
            }
        }
        foreach ($checked as $name => $value) {
            $this->store->setField($extension, $owner, $id, $name, $value);
        }

        return $this->of($owner, $id)[$extension] ?? [];
    }

    /** A field's value as one cell of a file or one line of a template: lists and maps as JSON, yes and no for a boolean. */
    public static function text(FieldSpec $field, mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if ($field->kind === 'boolean') {
            return Ops::truthy($value) ? 'yes' : 'no';
        }
        if (\is_array($value)) {
            if (isset($value['$ref'])) {
                return \is_scalar($value['id'] ?? null) ? (string) $value['id'] : '';
            }

            return (string) json_encode($value, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        }

        return Ops::text($value);
    }

    /**
     * A cell of an imported file as the field's value: empty clears; yes, no, true, false, 1 and 0 for a boolean;
     * JSON for a list or a map.
     *
     * @throws \InvalidArgumentException for text the field does not take, with why
     */
    public static function parse(FieldSection $section, FieldSpec $field, string $text): mixed
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }
        $value = match ($field->kind) {
            'boolean' => match (strtolower($text)) {
                'yes', 'true', '1', 'on' => true,
                'no', 'false', '0', 'off' => false,
                default => throw new \InvalidArgumentException(\sprintf('"%s" is not yes or no.', $text)),
            },
            'number' => is_numeric($text) ? (float) $text : throw new \InvalidArgumentException(\sprintf('"%s" is not a number.', $text)),
            'list', 'map' => \is_array($decoded = json_decode($text, true)) ? $decoded : throw new \InvalidArgumentException(\sprintf('"%s" is not JSON of a %s.', $text, $field->kind)),
            default => $text,
        };
        try {
            return ObjectDefinitions::check($section->declared(), [$field->name => $value], false)[$field->name];
        } catch (ScriptError $e) {
            throw new \InvalidArgumentException($e->getMessage(), 0, $e);
        }
    }

    /**
     * @param array<string, mixed>    $options
     * @param \Closure(string): string $word    the declared words in the language shown
     */
    private static function spec(string $name, string $kind, array $options, \Closure $word): FieldSpec
    {
        $label = $options['label'] ?? null;
        $help = $options['help'] ?? null;
        $of = [];
        foreach (\is_array($options['of'] ?? null) ? $options['of'] : [] as $choice) {
            $of[] = ObjectDefinitions::word($choice);
        }

        return new FieldSpec(
            $name,
            $kind,
            \is_string($label) && $label !== '' ? $word($label) : ucfirst(str_replace('_', ' ', $name)),
            \is_string($help) && $help !== '' ? $word($help) : null,
            ($options['required'] ?? false) === true,
            $options['default'] ?? null,
            $of,
            ($options['readonly'] ?? false) === true,
            ($options['filter'] ?? false) === true,
            ($options['column'] ?? true) !== false,
            \is_string($label) && $label !== '' ? $label : ucfirst(str_replace('_', ' ', $name)),
        );
    }
}
