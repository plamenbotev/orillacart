<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Application\Dto;

use OrillaCart\Shipping\Domain\Carrier;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Command: create a carrier, or replace one's name, state, position and whole list of rates.
 */
final readonly class SaveCarrier
{
    /**
     * @param list<SaveShippingRate> $rates in the order checkout lists them
     */
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: Carrier::MAX_NAME_LENGTH)]
        public string $name,
        public bool $active = true,
        #[Assert\PositiveOrZero]
        public int $position = 0,
        #[Assert\Valid]
        public array $rates = [],
    ) {}
}
