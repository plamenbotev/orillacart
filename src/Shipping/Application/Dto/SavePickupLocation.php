<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Application\Dto;

use OrillaCart\Shipping\Domain\PickupLocation;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One of the shop's places to collect orders, as the Shipping screen saves it.
 */
final readonly class SavePickupLocation
{
    public function __construct(
        /** Lowercase letters, digits and dashes; stays the same while the place is edited. */
        #[Assert\Regex(pattern: '/^[a-z0-9][a-z0-9-]{0,39}$/', message: 'Use lowercase letters, digits and dashes, up to 40.')]
        public string $id,
        #[Assert\NotBlank]
        #[Assert\Length(max: PickupLocation::MAX_NAME_LENGTH)]
        public string $name,
        #[Assert\NotBlank]
        #[Assert\Length(max: PickupLocation::MAX_TEXT_LENGTH)]
        public string $address,
        /** When it is open: "Mon–Fri 9–18, Sat 10–14". */
        #[Assert\Length(max: PickupLocation::MAX_TEXT_LENGTH)]
        public string $hours = '',
        #[Assert\Regex(pattern: SaveRange::MEASURE, message: 'Use an amount of zero or more with up to four decimals.')]
        public string $fee = '0',
    ) {}
}
