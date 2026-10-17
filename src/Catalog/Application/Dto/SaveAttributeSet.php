<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Application\Dto;

use OrillaCart\Catalog\Domain\AttributeSet;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Command: create an attribute set, or replace one's name, state and whole list of attributes.
 */
final readonly class SaveAttributeSet
{
    /**
     * @param list<SaveAttribute> $attributes in display order
     */
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: AttributeSet::MAX_NAME_LENGTH)]
        public string $name,
        public bool $active = true,
        #[Assert\Valid]
        public array $attributes = [],
    ) {}
}
