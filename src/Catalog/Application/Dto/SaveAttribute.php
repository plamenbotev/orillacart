<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Application\Dto;

use OrillaCart\Catalog\Domain\AttributeDraft;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * An attribute being saved, with all its options in display order; an existing attribute keeps its id.
 */
final readonly class SaveAttribute
{
    /**
     * @param list<SaveOption> $options
     */
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: AttributeDraft::MAX_NAME_LENGTH)]
        public string $name,
        #[Assert\Count(min: 1, minMessage: 'Give the attribute at least one option.')]
        #[Assert\Valid]
        public array $options,
        public bool $required = false,
        public bool $priceHidden = false,
        #[Assert\Positive]
        public ?int $id = null,
    ) {}
}
