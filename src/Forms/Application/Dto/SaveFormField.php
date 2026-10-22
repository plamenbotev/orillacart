<?php

declare(strict_types=1);

namespace OrillaCart\Forms\Application\Dto;

use OrillaCart\Forms\Domain\FieldKind;
use OrillaCart\Forms\Domain\FieldSection;
use OrillaCart\Forms\Domain\FormField;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A field being saved. Its key is made from the label when empty, and kept once made: answers and conditions name it.
 */
final readonly class SaveFormField
{
    /**
     * @param list<SaveFieldChoice> $choices
     * @param list<string>          $types
     */
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: FormField::MAX_LABEL_LENGTH)]
        public string $label,
        #[Assert\Choice(callback: [FieldKind::class, 'values'])]
        public string $kind = 'text',
        #[Assert\Length(max: 30)]
        public string $key = '',
        public bool $required = false,
        #[Assert\Length(max: FormField::MAX_HELP_LENGTH)]
        public string $help = '',
        #[Assert\Length(max: FormField::MAX_LABEL_LENGTH)]
        public string $placeholder = '',
        #[Assert\Range(min: 1, max: FormField::MAX_LONGEST)]
        public int $longest = FormField::MAX_TEXT_LENGTH,
        #[Assert\Valid]
        #[Assert\Count(max: FormField::MAX_CHOICES)]
        public array $choices = [],
        #[Assert\Choice(choices: [FormField::LIST, FormField::BUTTONS])]
        public string $display = FormField::LIST,
        #[Assert\Regex(pattern: '/^\d{1,11}(\.\d{1,4})?$/', message: 'Enter an amount.')]
        public ?string $price = null,
        #[Assert\Regex(pattern: '/^-?\d{1,11}(\.\d{1,4})?$/', message: 'Enter a number.')]
        public ?string $min = null,
        #[Assert\Regex(pattern: '/^-?\d{1,11}(\.\d{1,4})?$/', message: 'Enter a number.')]
        public ?string $max = null,
        public bool $whole = false,
        #[Assert\Choice(callback: [FieldSection::class, 'values'])]
        public string $section = 'order',
        #[Assert\Valid]
        public ?SaveFieldCondition $condition = null,
        #[Assert\All([new Assert\Choice(choices: FormField::FILE_TYPES)])]
        public array $types = FormField::FILE_TYPES,
        #[Assert\Range(min: 1, max: FormField::MAX_MEGABYTES)]
        public int $largest = 10,
    ) {}
}
