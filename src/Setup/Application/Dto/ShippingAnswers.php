<?php

declare(strict_types=1);

namespace OrillaCart\Setup\Application\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * How the shop ships: one way, priced for each zone the shop sells to, and a place to collect orders. Couriers'
 * own prices (add-ons) are set up on their carriers, which keep their keys there.
 */
final readonly class ShippingAnswers
{
    /**
     * @param list<ZoneShipping> $zones
     */
    public function __construct(
        /** What checkout calls it. */
        #[Assert\NotBlank]
        #[Assert\Length(max: 100)]
        public string $name,
        #[Assert\Count(max: 10)]
        #[Assert\Valid]
        public array $zones = [],
        #[Assert\Valid]
        public ?PickupAnswers $pickup = null,
    ) {}

    public function zone(string $id): ?ZoneShipping
    {
        foreach ($this->zones as $zone) {
            if ($zone->zone === $id) {
                return $zone;
            }
        }

        return null;
    }
}
