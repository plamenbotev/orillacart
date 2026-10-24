<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Application\Dto;

use OrillaCart\Shipping\Domain\Carrier;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Command: create a carrier, or replace one's name, state, position and whole list of rates; or set it up with
 * an add-on's shipping method, which then prices it (and it has no rates of its own); or make it the shop's own
 * pickup at its places (no rates, no method; the method's tax settings tax the fees).
 */
final readonly class SaveCarrier
{
    /**
     * @param list<SaveShippingRate>     $rates          in the order checkout lists them
     * @param array<string, string|bool> $methodSettings the method's settings; an empty secret keeps the saved one
     * @param list<SavePickupLocation>   $pickupLocations the shop's places to collect orders, in the order checkout lists them
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
        #[Assert\Regex(pattern: '/^[a-z][a-z0-9_]{0,39}$/')]
        public ?string $method = null,
        public array $methodSettings = [],
        public bool $methodTaxable = true,
        #[Assert\Positive]
        public ?int $methodTaxGroupId = null,
        #[Assert\Valid]
        #[Assert\Count(max: 100)]
        public array $pickupLocations = [],
        /** The days, and times of day, the customer chooses for delivery; null to leave them out. */
        #[Assert\Valid]
        public ?SaveDeliverySchedule $deliverySchedule = null,
        /** Goods of a class one of its rates lists go only by the rates that list it. */
        public bool $classesKeptToTheirRates = false,
    ) {}
}
