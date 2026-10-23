<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Application\Dto;

use OrillaCart\Tax\Domain\ExemptionReason;
use OrillaCart\Tax\Domain\TaxExemption;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Command: record a customer's exemption from tax, from the certificate they gave the shop.
 */
final readonly class SaveTaxExemption
{
    public function __construct(
        #[Assert\Choice(callback: [ExemptionReason::class, 'values'])]
        public string $reason,
        #[Assert\NotBlank]
        #[Assert\Length(max: TaxExemption::MAX_CERTIFICATE_LENGTH)]
        public string $certificate,
        /** @var list<string> countries ("US") and states ("US-TX") it holds in; empty for everywhere */
        #[Assert\Count(max: 100)]
        #[Assert\All([new Assert\Type('string'), new Assert\Regex(TaxExemption::REGION, message: 'Use a country or a state, such as US or US-TX.')])]
        public array $regions = [],
        /** Its last day, Y-m-d; null when it does not expire. */
        #[Assert\Date]
        public ?string $expiresOn = null,
        #[Assert\Length(max: TaxExemption::MAX_NOTE_LENGTH)]
        public string $note = '',
    ) {}
}
