<?php

declare(strict_types=1);

namespace OrillaCart\Forms\Application;

use OrillaCart\Forms\Domain\Form;
use OrillaCart\Forms\Domain\FormRepository;
use OrillaCart\Shared\Domain\Text;
use OrillaCart\Translations\Application\Dto\PackageString;
use OrillaCart\Translations\Application\Dto\TranslationPackage;
use OrillaCart\Translations\Application\TranslationPackageSource;

/** A form's words for the translation service: each field's label, help, placeholder and choices. */
final readonly class FormPackages implements TranslationPackageSource
{
    public const string KIND = 'form';

    private const array PART_TITLES = ['label' => '%s: question', 'help' => '%s: help', 'placeholder' => '%s: placeholder'];

    public function __construct(private FormRepository $forms) {}

    public function kind(): string
    {
        return self::KIND;
    }

    public function title(): string
    {
        return 'OrillaCart form';
    }

    public function package(int $id): ?TranslationPackage
    {
        $form = $this->forms->find($id);
        if ($form === null) {
            return null;
        }
        $strings = [];
        foreach ($form->fields() as $field) {
            foreach (FormTranslations::PARTS as $part) {
                $strings[] = new PackageString(FormTranslations::TYPE, $id, FormTranslations::part($field->key, $part), $field->{$part}, Text::of(self::PART_TITLES[$part], $field->label), $part === 'help');
            }
            foreach ($field->choices as $choice) {
                $strings[] = new PackageString(FormTranslations::TYPE, $id, FormTranslations::choice($field->key, $choice->value), $choice->label, Text::of('%1$s: choice %2$s', $field->label, $choice->label));
            }
        }

        return TranslationPackage::of(self::KIND, $id, $form->name(), $strings);
    }

    public function ids(): iterable
    {
        return array_map(static fn(Form $form): int => $form->id(), $this->forms->all());
    }
}
