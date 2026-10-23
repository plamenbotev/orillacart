<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Application\Dto;

use OrillaCart\Tax\Domain\TaxExemption;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Command: the shop refuses a certificate the customer sent, telling them why.
 */
final readonly class RefuseTaxExemption
{
    public function __construct(
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Length(max: TaxExemption::MAX_NOTE_LENGTH)]
        public string $reason,
    ) {}
}
