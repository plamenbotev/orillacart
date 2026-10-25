<?php

declare(strict_types=1);

namespace OrillaCart\Setup\Application\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * What shipping costs to one zone: one price, a price and free from an amount, a price by weight, or no shipping
 * there.
 */
final readonly class ZoneShipping
{
    public const string FLAT = 'flat';
    public const string FREE_ABOVE = 'free_above';
    public const string BY_WEIGHT = 'weight';
    public const string NONE = 'none';
    private const string AMOUNT = '/^\d{1,11}(\.\d{1,4})?$/';

    /**
     * @param list<WeightPrice> $weights
     */
    public function __construct(
        #[Assert\Regex('/^[a-z_]{1,20}$/')]
        public string $zone,
        #[Assert\Choice(choices: [self::FLAT, self::FREE_ABOVE, self::BY_WEIGHT, self::NONE])]
        public string $prices,
        #[Assert\Regex(self::AMOUNT)]
        public string $price = '0',
        #[Assert\Regex(self::AMOUNT)]
        public ?string $freeAbove = null,
        #[Assert\Count(max: 20)]
        #[Assert\Valid]
        public array $weights = [],
    ) {}
}
