<?php

declare(strict_types=1);

namespace OrillaCart\Translations\Application\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Which objects' translations to read: a type's, by id, comma-separated.
 */
final readonly class TranslationsQuery
{
    public const int MAX_IDS = 500;

    public function __construct(
        #[Assert\NotBlank]
        public string $type = '',
        #[Assert\Regex('/^\d+(,\d+)*$/')]
        public string $ids = '',
    ) {}

    /** @return list<int> */
    public function idList(): array
    {
        return $this->ids === '' ? [] : array_values(array_unique(array_map(intval(...), explode(',', $this->ids))));
    }
}
