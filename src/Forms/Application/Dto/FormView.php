<?php

declare(strict_types=1);

namespace OrillaCart\Forms\Application\Dto;

use OrillaCart\Forms\Application\FormTranslations;
use OrillaCart\Forms\Domain\FieldChoice;
use OrillaCart\Forms\Domain\Form;
use OrillaCart\Forms\Domain\FormField;

final readonly class FormView
{
    /**
     * @param list<int>                  $categoryIds
     * @param list<int>                  $productIds
     * @param list<array<string, mixed>> $fields      as saved: key, label, kind and the rest
     */
    public function __construct(
        public int $id,
        public string $name,
        public string $placement,
        public bool $active,
        public int $position,
        public string $scope,
        public array $categoryIds,
        public array $productIds,
        public array $fields,
    ) {}

    /**
     * @param array<string, array<string, string>> $translations the form's, by the table's field ("field.size.label") and locale
     */
    public static function fromForm(Form $form, array $translations = []): self
    {
        return new self($form->id(), $form->name(), $form->placement()->value, $form->isActive(), $form->position(), $form->scope()->value, $form->categoryIds(), $form->productIds(), array_map(static fn(FormField $field): array => self::row($field, $translations), $form->fields()));
    }

    /**
     * The field as saved, with its words' translations: its parts' by part and locale, each choice's by locale.
     *
     * @param array<string, array<string, string>> $translations
     *
     * @return array<string, mixed>
     */
    private static function row(FormField $field, array $translations): array
    {
        $row = $field->toRow();
        $parts = [];
        foreach (FormTranslations::PARTS as $part) {
            $values = $translations[FormTranslations::part($field->key, $part)] ?? [];
            if ($values !== []) {
                $parts[$part] = $values;
            }
        }
        $row['translations'] = $parts;
        $row['choices'] = array_map(static fn(FieldChoice $choice): array => ['value' => $choice->value, 'label' => $choice->label, 'price' => $choice->price, 'translations' => $translations[FormTranslations::choice($field->key, $choice->value)] ?? []], $field->choices);

        return $row;
    }
}
