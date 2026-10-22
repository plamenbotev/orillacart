<?php

declare(strict_types=1);

namespace OrillaCart\Forms\Domain;

enum FieldKind: string
{
    case Text = 'text';
    case Textarea = 'textarea';
    case Number = 'number';
    /** A day, kept as YYYY-MM-DD. */
    case Date = 'date';
    /** Yes or no; required means it has to be ticked. */
    case Checkbox = 'checkbox';
    /** One of the field's choices. */
    case Choice = 'choice';
    /** A picture or document the shopper sends, kept among the shop's private files; product forms only. */
    case File = 'file';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn(self $kind): string => $kind->value, self::cases());
    }
}
