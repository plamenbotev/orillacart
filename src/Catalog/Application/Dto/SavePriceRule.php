<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Application\Dto;

use OrillaCart\Catalog\Domain\PriceRule;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Command: a price rule as the shop sets it up. Empty lists mean everyone and everything.
 */
final readonly class SavePriceRule
{
    /**
     * @param list<PriceTierData> $tiers
     * @param list<int>           $groupIds
     * @param list<int>           $productIds
     * @param list<int>           $categoryIds
     * @param list<int>           $brandIds
     */
    public function __construct(
        #[Assert\NotBlank(normalizer: 'trim', message: 'Name the rule.')]
        #[Assert\Length(max: PriceRule::MAX_NAME_LENGTH)]
        public string $name,
        #[Assert\Count(min: 1, max: 20, minMessage: 'Give the rule a price.')]
        #[Assert\Valid]
        public array $tiers,
        #[Assert\Count(max: 100)]
        public array $groupIds = [],
        #[Assert\Count(max: 1000)]
        public array $productIds = [],
        #[Assert\Count(max: 1000)]
        public array $categoryIds = [],
        #[Assert\Count(max: 1000)]
        public array $brandIds = [],
        #[Assert\DateTime(format: \DATE_ATOM)]
        public ?string $startsAt = null,
        #[Assert\DateTime(format: \DATE_ATOM)]
        public ?string $endsAt = null,
        public bool $withSales = false,
        public bool $active = true,
    ) {}
}
