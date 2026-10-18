<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Application\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class SaveBundleItem
{
    /**
     * @param list<int> $variationIds for a product with variations: one that the bundle holds, or several the customer chooses from
     * @param bool      $optional     left out unless the customer adds it
     */
    public function __construct(
        #[Assert\Positive]
        public int $productId,
        #[Assert\Range(min: 1, max: 9999)]
        public int $quantity = 1,
        #[Assert\Count(max: 100)]
        #[Assert\All([new Assert\Positive()])]
        public array $variationIds = [],
        public bool $optional = false,
    ) {}
}
