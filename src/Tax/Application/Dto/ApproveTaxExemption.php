<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Application\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Command: the shop approves a certificate the customer sent, saying where it holds and until when, as it read
 * them on the copy: what the customer typed is not taken as it is.
 */
final readonly class ApproveTaxExemption
{
    /**
     * @param list<string> $regions countries and states, such as US-TX; at least one
     */
    public function __construct(
        #[Assert\Count(min: 1, minMessage: 'Say where the certificate holds, such as US-TX.')]
        #[Assert\All([new Assert\Type('string')])]
        public array $regions,
        /** Its last day, "2027-12-31"; null for one that does not expire. */
        #[Assert\Date]
        public ?string $expiresOn = null,
    ) {}
}
