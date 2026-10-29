<?php

declare(strict_types=1);

namespace OrillaCart\Forms\Application;

use OrillaCart\Forms\Domain\FieldChoice;
use OrillaCart\Forms\Domain\FormField;
use OrillaCart\Translations\Application\TranslatableType;

/** A form's fields' labels, help, placeholders and choices in the site's other languages, by the field's key. */
final readonly class FormTranslations implements TranslatableType
{
    public const string TYPE = 'form';

    /** A field's own translated words. */
    public const array PARTS = ['label', 'help', 'placeholder'];

    public function type(): string
    {
        return self::TYPE;
    }

    public function fields(): array
    {
        return [
            'field.{key}.label' => FormField::MAX_LABEL_LENGTH,
            'field.{key}.help' => FormField::MAX_HELP_LENGTH,
            'field.{key}.placeholder' => FormField::MAX_LABEL_LENGTH,
            'field.{key}.choice.{key}' => FieldChoice::MAX_LABEL_LENGTH,
        ];
    }

    public static function part(string $fieldKey, string $part): string
    {
        return "field.{$fieldKey}.{$part}";
    }

    public static function choice(string $fieldKey, string $value): string
    {
        return "field.{$fieldKey}.choice.{$value}";
    }
}
