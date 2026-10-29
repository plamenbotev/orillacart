<?php

declare(strict_types=1);

namespace OrillaCart\Translations\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * A field of something in the shop's own tables (an attribute's name, a shipping method's title) in another
 * language than its own (design 51): the field keeps the site's default language, a translation each other one.
 * Read and written with SQL (DbalTranslationRepository); mapped so migrations know the table.
 */
#[ORM\Entity]
#[ORM\Table(name: 'translations')]
#[ORM\UniqueConstraint(name: 'translation_field', columns: ['object_type', 'object_id', 'field', 'locale'])]
#[ORM\Index(name: 'translation_locale', columns: ['object_type', 'locale'])]
class Translation
{
    public const int MAX_TYPE_LENGTH = 40;
    /** The longest a field may be named: "field.{key}.choice.{value}" of a form, 84 at most. */
    public const int MAX_FIELD_LENGTH = 100;
    public const int MAX_LOCALE_LENGTH = 20;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    public function __construct(
        #[ORM\Column(length: self::MAX_TYPE_LENGTH)]
        private string $objectType,
        #[ORM\Column]
        private int $objectId,
        #[ORM\Column(length: self::MAX_FIELD_LENGTH)]
        private string $field,
        /** WordPress's locale, such as de_DE: what emails switch to, and the same whichever plugin translates. */
        #[ORM\Column(length: self::MAX_LOCALE_LENGTH)]
        private string $locale,
        #[ORM\Column(type: 'text')]
        private string $value,
    ) {}
}
