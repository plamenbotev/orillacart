<?php

declare(strict_types=1);

namespace OrillaCart\Forms\Application\Dto;

use OrillaCart\Forms\Domain\FieldChoice;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class SaveFieldChoice
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: FieldChoice::MAX_LABEL_LENGTH)]
        public string $label,
        /** Made from the label when empty; kept once made, as answers and conditions name it. */
        #[Assert\Length(max: 40)]
        public string $value = '',
        #[Assert\Regex(pattern: '/^\d{1,11}(\.\d{1,4})?$/', message: 'Enter an amount.')]
        public ?string $price = null,
    ) {}
}
