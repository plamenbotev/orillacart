<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Application\Dto;

use OrillaCart\Catalog\Application\ProductFinder;
use OrillaCart\Catalog\Domain\ProductCriteria;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Query: the products the shop's list narrows to, by their commerce details and stock. Each given narrows it.
 */
final readonly class ProductFilter
{
    public function __construct(
        #[Assert\Choice(choices: ProductCriteria::KINDS)]
        public ?string $kind = null,
        #[Assert\Choice(choices: ProductFinder::STOCK)]
        public ?string $stock = null,
        /** Stock counted in this stockroom only; alone, the products it holds some of. */
        #[Assert\Positive]
        public ?int $stockroom = null,
        #[Assert\Choice(choices: ProductCriteria::SHOWN)]
        public ?string $shown = null,
        #[Assert\Regex(pattern: '/^\d{1,11}(\.\d{1,4})?$/')]
        public ?string $minPrice = null,
        #[Assert\Regex(pattern: '/^\d{1,11}(\.\d{1,4})?$/')]
        public ?string $maxPrice = null,
        /** 0 for products without a shipping class. */
        #[Assert\PositiveOrZero]
        public ?int $shippingClass = null,
        #[Assert\Positive]
        public ?int $attributeSet = null,
        #[Assert\Choice(choices: ProductCriteria::MISSING)]
        public ?string $missing = null,
        /** An add-on's filter, by its id. */
        #[Assert\Regex(pattern: '/^[a-z][a-z0-9_]{0,39}$/')]
        public ?string $addOn = null,
    ) {}
}
