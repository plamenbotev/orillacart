<?php

declare(strict_types=1);

namespace OrillaCart\Translations\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * What a translation service (WPML) last gave for a field in a language: the shop takes the service's word only
 * when it differs from this, so a translation fixed in the shop's own screens stays until the service's
 * translators change theirs. Read and written with SQL; mapped so migrations know the table.
 */
#[ORM\Entity]
#[ORM\Table(name: 'translation_service_copies')]
#[ORM\UniqueConstraint(name: 'service_copy_field', columns: ['object_type', 'object_id', 'field', 'locale'])]
class ServiceCopy
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    public function __construct(
        #[ORM\Column(length: Translation::MAX_TYPE_LENGTH)]
        private string $objectType,
        #[ORM\Column]
        private int $objectId,
        #[ORM\Column(length: Translation::MAX_FIELD_LENGTH)]
        private string $field,
        #[ORM\Column(length: Translation::MAX_LOCALE_LENGTH)]
        private string $locale,
        #[ORM\Column(type: 'text')]
        private string $value,
    ) {}
}
