<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Application\Dto;

use OrillaCart\Tax\Domain\TaxGroup;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Command: create a tax group, or rename one.
 */
final readonly class SaveTaxGroup
{
    public function __construct(
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Length(max: TaxGroup::MAX_NAME_LENGTH)]
        public string $name,
    ) {}
}
