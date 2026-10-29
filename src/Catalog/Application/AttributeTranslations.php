<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Application;

use OrillaCart\Catalog\Domain\AttributeDraft;
use OrillaCart\Translations\Application\TranslatableType;

/** The names of attributes, a product's own and attribute sets', in the site's other languages. */
final readonly class AttributeTranslations implements TranslatableType
{
    public const string TYPE = 'attribute';

    public function type(): string
    {
        return self::TYPE;
    }

    public function fields(): array
    {
        return ['name' => AttributeDraft::MAX_NAME_LENGTH];
    }
}
