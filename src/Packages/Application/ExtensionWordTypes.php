<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Application;

use OrillaCart\Packages\Application\Dto\ExtensionWord;
use OrillaCart\Translations\Application\TranslatableType;

/** An extension's words in the site's other languages (design 57): each word's forms by its key. */
final readonly class ExtensionWordTypes implements TranslatableType
{
    public const string TYPE = 'extension';

    /** The longest translation of one word. */
    public const int LONGEST = 2000;

    public function type(): string
    {
        return self::TYPE;
    }

    public function fields(): array
    {
        return [ExtensionWord::FIELD . '{key}' => self::LONGEST];
    }
}
