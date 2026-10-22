<?php

declare(strict_types=1);

namespace OrillaCart\Forms\Application\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class SaveFieldCondition
{
    /**
     * @param list<string> $values the other field's choice values; none for any answer
     */
    public function __construct(
        #[Assert\NotBlank]
        public string $field,
        #[Assert\All([new Assert\Type('string')])]
        public array $values = [],
    ) {}
}
