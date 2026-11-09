<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Application;

use OrillaCart\Packages\Application\Dto\FieldSection;
use OrillaCart\Packages\Application\Dto\FieldSpec;
use OrillaCart\Search\Application\Dto\DocumentOption;
use OrillaCart\Search\Application\FacetSource;

/**
 * The extensions' product fields that say `filter: true` as facets of the search index: `fields on product {
 * material: choice { of: ["wool", "cotton"], label: "Material", filter: true } }` puts each product's material
 * into its search document as an option of the attribute "Material", so a search filter on "Material" works
 * as one on a real attribute does. A list field gives one option per item; a boolean, yes or no.
 */
final readonly class ExtensionFieldFacets implements FacetSource
{
    public function __construct(private ExtensionFields $fields) {}

    public function options(array $productIds): array
    {
        $filtered = $this->filtered();
        if ($filtered === [] || $productIds === []) {
            return [];
        }
        $options = [];
        foreach ($this->fields->ofMany('product', $productIds) as $productId => $byExtension) {
            foreach ($filtered as [$section, $fields]) {
                foreach ($fields as $field) {
                    foreach (self::names($field, $byExtension[$section->extension][$field->name] ?? null) as $name) {
                        $options[$productId][] = new DocumentOption($field->declaredLabel(), $name);
                    }
                }
            }
        }

        return $options;
    }

    public function attributeNames(): array
    {
        $names = [];
        foreach ($this->filtered() as [, $fields]) {
            foreach ($fields as $field) {
                $names[] = $field->declaredLabel();
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * @return list<array{FieldSection, non-empty-list<FieldSpec>}> the sections with the fields of theirs that filter
     */
    private function filtered(): array
    {
        $filtered = [];
        foreach ($this->fields->declared('product') as $section) {
            $fields = array_values(array_filter($section->fields, static fn(FieldSpec $field): bool => $field->filter));
            if ($fields !== []) {
                $filtered[] = [$section, $fields];
            }
        }

        return $filtered;
    }

    /** @return list<string> */
    private static function names(FieldSpec $field, mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if ($field->kind === 'list' && \is_array($value)) {
            $names = [];
            foreach ($value as $item) {
                if (\is_scalar($item) && (string) $item !== '') {
                    $names[] = (string) $item;
                }
            }

            return $names;
        }

        return [ExtensionFields::text($field, $value)];
    }
}
