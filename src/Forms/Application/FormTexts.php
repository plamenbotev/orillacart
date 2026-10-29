<?php

declare(strict_types=1);

namespace OrillaCart\Forms\Application;

use OrillaCart\Forms\Application\Dto\SaveFieldChoice;
use OrillaCart\Forms\Application\Dto\SaveForm;
use OrillaCart\Forms\Application\Dto\SaveFormField;
use OrillaCart\Forms\Domain\FieldChoice;
use OrillaCart\Forms\Domain\Form;
use OrillaCart\Forms\Domain\FormField;
use OrillaCart\Translations\Application\ContentTranslations;
use OrillaCart\Translations\Domain\TranslationRuleViolation;

/**
 * A form's words in the site's other languages (design 51): its fields' labels, help and placeholders and their
 * choices' labels, saved with the form. A new field's key is made in the save, so they are checked first and keyed
 * once the form has its fields; a field or choice the save removed takes its words with it.
 */
final readonly class FormTexts
{
    /** Stands in for a field's or choice's key while the save has not made it yet. */
    private const string UNKEYED = 'k';

    public function __construct(private ContentTranslations $translations) {}

    /**
     * @return array<string, array<string, string>> by the table's field ("field.size.label") and locale
     */
    public function of(Form $form): array
    {
        return $this->translations->of(FormTranslations::TYPE, [$form->id()])[$form->id()] ?? [];
    }

    /**
     * Checked before the save: a refused translation refuses it, before anything is written.
     *
     * @return list<array{?array<string, array<string, string>>, list<?array<string, string>>}> each field's parts
     *                                                                                          and its choices' labels, null for none sent
     *
     * @throws TranslationRuleViolation
     */
    public function check(SaveForm $command): array
    {
        return array_map(function (SaveFormField $field): array {
            $parts = null;
            if ($field->translations !== null) {
                $checked = $this->translations->check(FormTranslations::TYPE, array_combine(
                    array_map(static fn(string $part): string => FormTranslations::part(self::UNKEYED, $part), FormTranslations::PARTS),
                    array_map(static fn(string $part): array => $field->translations[$part] ?? [], FormTranslations::PARTS),
                ));
                $parts = array_combine(FormTranslations::PARTS, array_map(static fn(string $part): array => $checked[FormTranslations::part(self::UNKEYED, $part)] ?? [], FormTranslations::PARTS));
            }

            return [$parts, array_map(fn(SaveFieldChoice $choice): ?array => $choice->translations === null ? null
                : $this->translations->check(FormTranslations::TYPE, [FormTranslations::choice(self::UNKEYED, self::UNKEYED) => $choice->translations])[FormTranslations::choice(self::UNKEYED, self::UNKEYED)] ?? [], $field->choices)];
        }, $command->fields);
    }

    /**
     * Writes what check() returned once the form is saved, by its fields' keys, and forgets the words of the fields
     * and choices it no longer has.
     *
     * @param list<array{?array<string, array<string, string>>, list<?array<string, string>>}> $checked
     */
    public function write(array $checked, Form $form): void
    {
        $fields = [];
        $kept = [];
        foreach ($form->fields() as $i => $field) {
            [$parts, $choices] = $checked[$i] ?? [null, []];
            foreach (FormTranslations::PARTS as $part) {
                $kept[FormTranslations::part($field->key, $part)] = true;
                if ($parts !== null) {
                    $fields[FormTranslations::part($field->key, $part)] = $parts[$part];
                }
            }
            foreach ($field->choices as $j => $choice) {
                $kept[FormTranslations::choice($field->key, $choice->value)] = true;
                if (($choices[$j] ?? null) !== null) {
                    $fields[FormTranslations::choice($field->key, $choice->value)] = $choices[$j];
                }
            }
        }
        foreach (array_keys($this->of($form)) as $stored) {
            if (!isset($kept[$stored])) {
                $fields[$stored] = [];
            }
        }
        $this->translations->write(FormTranslations::TYPE, $form->id(), $fields);
    }

    public function forget(Form $form): void
    {
        $this->translations->forget(FormTranslations::TYPE, [$form->id()]);
    }

    /**
     * The field as the shopper is asked it: its words in the language shown, else its own.
     *
     * @param array<string, string> $shown the form's translations in that language, by the table's field
     */
    public static function shown(FormField $field, array $shown): FormField
    {
        $text = static fn(string $part, string $own): string => $shown[FormTranslations::part($field->key, $part)] ?? $own;

        return $field->worded(
            $text('label', $field->label),
            $text('help', $field->help),
            $text('placeholder', $field->placeholder),
            array_map(static fn(FieldChoice $choice): FieldChoice => new FieldChoice($choice->value, $shown[FormTranslations::choice($field->key, $choice->value)] ?? $choice->label, $choice->price), $field->choices),
        );
    }

    /**
     * The forms' translations in the language shown.
     *
     * @param list<Form> $forms
     *
     * @return array<int, array<string, string>> by form id and the table's field
     */
    public function shownFor(array $forms): array
    {
        return $this->translations->shown(FormTranslations::TYPE, array_map(static fn(Form $form): int => $form->id(), $forms));
    }
}
