<?php

declare(strict_types=1);

namespace OrillaCart\Forms\Application\Dto;

use OrillaCart\Forms\Domain\Form;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Command: create a form, or replace one's settings and all its fields.
 */
final readonly class SaveForm
{
    /**
     * @param list<int>           $categoryIds
     * @param list<int>           $productIds
     * @param list<SaveFormField> $fields      in order
     */
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: Form::MAX_NAME_LENGTH)]
        public string $name,
        #[Assert\Choice(choices: ['product', 'checkout'])]
        public string $placement = 'product',
        public bool $active = true,
        public int $position = 0,
        #[Assert\Choice(choices: ['all', 'categories', 'products'])]
        public string $scope = 'all',
        #[Assert\All([new Assert\Positive()])]
        public array $categoryIds = [],
        #[Assert\All([new Assert\Positive()])]
        public array $productIds = [],
        #[Assert\Valid]
        #[Assert\Count(max: Form::MAX_FIELDS)]
        public array $fields = [],
    ) {}
}
