<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Application\Dto;

use OrillaCart\Shipping\Domain\RateDetails;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One rate of a carrier as the admin saves it. Without an id it is a new rate. Ranges left out take any
 * parcel; weight is in the shop's weight unit, volume, length, width and height in its dimension unit
 * (volume cubed), subtotal in its currency, items as a count of units.
 */
final readonly class SaveShippingRate
{
    /**
     * @param list<string> $regions countries ("BG") or states ("US-CA"); empty for everywhere
     * @param list<int>    $shippingClasses the classes it carries, 0 for items without one; empty for every item
     * @param list<string> $postcodes postcodes ("1000"), starts ("SW1*") or numeric ranges ("1000-1999"); empty for any
     * @param list<string> $excludedRegions   countries or states it does not ship to, even within its regions
     * @param list<string> $excludedPostcodes postcode patterns it does not ship to
     */
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: RateDetails::MAX_NAME_LENGTH)]
        public string $name,
        #[Assert\Regex(pattern: SaveRange::MEASURE, message: 'Use an amount of zero or more with up to four decimals.')]
        public string $price,
        #[Assert\Positive]
        public ?int $id = null,
        public bool $perItem = false,
        #[Assert\All([new Assert\Regex(pattern: '/^[A-Z]{2}(-[A-Z0-9]{1,3})?$/', message: 'Use a country code such as "BG" or a state such as "US-CA".')])]
        public array $regions = [],
        #[Assert\Valid]
        public ?SaveRange $weight = null,
        #[Assert\Valid]
        public ?SaveRange $volume = null,
        #[Assert\Valid]
        public ?SaveRange $length = null,
        #[Assert\Valid]
        public ?SaveRange $width = null,
        #[Assert\Valid]
        public ?SaveRange $height = null,
        #[Assert\Valid]
        public ?SaveRange $subtotal = null,
        public bool $taxable = true,
        #[Assert\Positive]
        public ?int $taxGroupId = null,
        #[Assert\All([new Assert\PositiveOrZero()])]
        public array $shippingClasses = [],
        #[Assert\All([new Assert\Length(max: 30)])]
        public array $postcodes = [],
        #[Assert\All([new Assert\Regex(pattern: '/^[A-Z]{2}(-[A-Z0-9]{1,3})?$/', message: 'Use a country code such as "BG" or a state such as "US-CA".')])]
        public array $excludedRegions = [],
        #[Assert\All([new Assert\Length(max: 30)])]
        public array $excludedPostcodes = [],
        #[Assert\Valid]
        public ?SaveRange $items = null,
        /**
         * The name in the site's other languages, by locale; null leaves them as they are.
         *
         * @var array<string, string>|null
         */
        #[Assert\All([new Assert\Type('string')])]
        public ?array $translations = null,
    ) {}
}
