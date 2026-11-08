<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Application\Dto;

/**
 * The fields one extension declared on one of the shop's kinds: a block of the "Extensions" section of the
 * product, order or customer screen, built from the declaration.
 */
final readonly class FieldSection
{
    /**
     * @param string          $extension the extension's id
     * @param string          $name      the extension's name, the block's title
     * @param list<FieldSpec> $fields
     */
    public function __construct(
        public string $extension,
        public string $name,
        public array $fields,
    ) {}

    /** @return array<string, array{string, array<string, mixed>}> as ObjectDefinitions::check() takes them */
    public function declared(): array
    {
        $declared = [];
        foreach ($this->fields as $field) {
            $declared[$field->name] = [$field->kind, ['required' => $field->required, 'default' => $field->default, 'of' => $field->of]];
        }

        return $declared;
    }

    public function field(string $name): ?FieldSpec
    {
        foreach ($this->fields as $field) {
            if ($field->name === $name) {
                return $field;
            }
        }

        return null;
    }
}
