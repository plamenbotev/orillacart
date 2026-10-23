<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Application\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Command part: a threshold of economic nexus, for every state or for one (with its code, "TX").
 */
final readonly class SaveNexusThreshold
{
    public function __construct(
        /** Sales in a year, in the shop's currency: "100000"; null when the state does not count them. */
        #[Assert\Regex('/^\d{1,11}(\.\d{1,2})?$/', message: 'Enter an amount such as 100000.')]
        public ?string $sales = null,
        /** Orders in a year; null when the state does not count them. */
        #[Assert\Positive]
        public ?int $orders = null,
        /** The state's code, "TX"; null for every state. */
        #[Assert\Regex('/^[A-Z]{2}$/', message: 'Use a state code, such as TX.')]
        public ?string $state = null,
    ) {}
}
