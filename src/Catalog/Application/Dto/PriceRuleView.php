<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Application\Dto;

use OrillaCart\Catalog\Domain\PriceRule;
use OrillaCart\Catalog\Domain\PriceTier;

final readonly class PriceRuleView
{
    /**
     * @param list<array{min_quantity: int, kind: string, value: string}> $tiers
     * @param list<int> $groupIds
     * @param list<int> $productIds
     * @param list<int> $categoryIds
     * @param list<int> $brandIds
     */
    public function __construct(
        public int $id,
        public string $name,
        public array $tiers,
        public array $groupIds,
        public array $productIds,
        public array $categoryIds,
        public array $brandIds,
        public ?string $startsAt,
        public ?string $endsAt,
        public bool $withSales,
        public bool $active,
        public bool $withCoupons = true,
        public bool $combines = false,
        /** @var list<int> the products it names that were deleted since: it prices nothing for them */
        public array $missingProductIds = [],
    ) {}

    /**
     * @param list<int> $missingProductIds the products it names that were deleted since
     */
    public static function fromRule(PriceRule $rule, array $missingProductIds = []): self
    {
        return new self(
            $rule->id(),
            $rule->name(),
            array_map(static fn(PriceTier $tier): array => ['min_quantity' => $tier->minQuantity, 'kind' => $tier->kind, 'value' => (string) $tier->value], $rule->tiers()),
            $rule->groupIds(),
            $rule->productIds(),
            $rule->categoryIds(),
            $rule->brandIds(),
            $rule->startsAt()?->format(\DATE_ATOM),
            $rule->endsAt()?->format(\DATE_ATOM),
            $rule->goesWithSales(),
            $rule->isActive(),
            $rule->goesWithCoupons(),
            $rule->combines(),
            $missingProductIds,
        );
    }
}
