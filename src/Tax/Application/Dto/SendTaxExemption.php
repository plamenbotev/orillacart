<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Application\Dto;

use OrillaCart\Tax\Domain\ExemptionReason;
use OrillaCart\Tax\Domain\TaxExemption;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Command: a customer sends their exemption certificate for the shop to check; its copy comes beside it.
 */
final readonly class SendTaxExemption
{
    /**
     * @param list<string> $regions the states it holds in, "US-TX"; at least one
     */
    public function __construct(
        #[Assert\Choice(callback: [ExemptionReason::class, 'values'])]
        public string $reason,
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Length(max: TaxExemption::MAX_CERTIFICATE_LENGTH)]
        public string $certificate,
        #[Assert\Count(min: 1, max: 60)]
        #[Assert\All([new Assert\Type('string'), new Assert\Regex('/^[A-Za-z]{2}-[A-Za-z0-9]{1,3}$/', message: 'Choose the states, such as US-TX.')])]
        public array $regions,
        /** Its last day, "2027-12-31"; null for one that does not run out. */
        #[Assert\Date]
        public ?string $expiresOn = null,
    ) {}
}
