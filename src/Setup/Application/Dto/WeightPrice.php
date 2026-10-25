<?php

declare(strict_types=1);

namespace OrillaCart\Setup\Application\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Up to a weight, in the shop's unit, a parcel costs this.
 */
final readonly class WeightPrice
{
    public function __construct(
        #[Assert\Regex('/^\d{1,10}(\.\d{1,4})?$/')]
        public string $upTo,
        #[Assert\Regex('/^\d{1,11}(\.\d{1,4})?$/')]
        public string $price,
    ) {}
}
