<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Domain;

use OrillaCart\Shared\Domain\Money;

/**
 * Where a rate ships, which parcels it takes and what it costs.
 */
final readonly class RateDetails
{
    public const int MAX_NAME_LENGTH = 100;

    /**
     * @param list<Region> $regions  where it ships; empty for everywhere
     * @param bool         $perItem  the price is for each item shipped, not for the parcel
     * @param int|null     $taxGroupId null for the shop's default tax group
     * @param list<int>    $classes  the shipping classes it carries (ShippingClass::NONE for items without one);
     *                               empty for every item. A parcel with an item it does not carry gets another rate.
     * @param list<PostcodePattern> $postcodes the postcodes it ships to within its regions; empty for any
     */
    public function __construct(
        public string $name,
        public Money $price,
        public bool $perItem = false,
        public array $regions = [],
        public Range $weight = new Range(),
        public Range $volume = new Range(),
        public Range $length = new Range(),
        public Range $width = new Range(),
        public Range $height = new Range(),
        public Range $subtotal = new Range(),
        public bool $taxable = true,
        public ?int $taxGroupId = null,
        public array $classes = [],
        public array $postcodes = [],
    ) {
        if (trim($name) === '' || mb_strlen(trim($name)) > self::MAX_NAME_LENGTH) {
            throw new ShippingRuleViolation(\sprintf('A shipping rate needs a name of up to %d characters.', self::MAX_NAME_LENGTH), 'orillacart_invalid_rate_name');
        }
        if ($price->isNegative()) {
            throw new ShippingRuleViolation('A shipping rate cannot cost less than nothing.', 'orillacart_invalid_rate_price');
        }
        $seen = [];
        foreach ($regions as $region) {
            if (isset($seen[(string) $region])) {
                throw new ShippingRuleViolation(\sprintf('The region %s is listed twice.', $region), 'orillacart_duplicate_region');
            }
            $seen[(string) $region] = true;
        }
        if (\count(array_unique($classes)) !== \count($classes)) {
            throw new ShippingRuleViolation('A shipping class is listed twice.', 'orillacart_duplicate_shipping_class');
        }
    }
}
