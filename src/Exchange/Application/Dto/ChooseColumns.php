<?php

declare(strict_types=1);

namespace OrillaCart\Exchange\Application\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Command: export these columns, by id.
 */
final readonly class ChooseColumns
{
    /**
     * @param list<string> $columns
     */
    public function __construct(
        #[Assert\Count(min: 1, minMessage: 'Choose at least one column.')]
        #[Assert\All([new Assert\Regex('/^[a-z][a-z0-9_]{0,39}$/')])]
        public array $columns = [],
    ) {}
}
