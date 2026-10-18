<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Application\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class SaveBundleItem
{
    public function __construct(
        #[Assert\Positive]
        public int $productId,
        #[Assert\Range(min: 1, max: 9999)]
        public int $quantity = 1,
        /** For a product with variations: which one. */
        #[Assert\Positive]
        public ?int $variationId = null,
    ) {}
}
