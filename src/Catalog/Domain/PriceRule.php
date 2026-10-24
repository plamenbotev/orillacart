<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Domain;

use Doctrine\ORM\Mapping as ORM;
use OrillaCart\Shared\Domain\Money;

/**
 * A price of the shop's own making: for everyone or some customer groups, on the whole catalog or some products,
 * categories or brands, between dates, by how many items are bought. A rule that does not say it goes with sales
 * leaves items on sale alone. Rules compete, the lowest price winning, unless one says it combines: it then applies
 * on top of the price the others give. A rule can keep codes and automatic discounts off the prices it sets.
 */
#[ORM\Entity]
#[ORM\Table(name: 'price_rules')]
class PriceRule
{
    public const int MAX_NAME_LENGTH = 100;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: self::MAX_NAME_LENGTH)]
    private string $name;

    #[ORM\Column]
    private bool $active;

    /** @var list<int> customer groups; none: everyone, guests too */
    #[ORM\Column(type: 'json')]
    private array $groupIds;

    /** @var list<int> */
    #[ORM\Column(type: 'json')]
    private array $productIds;

    /** @var list<int> */
    #[ORM\Column(type: 'json')]
    private array $categoryIds;

    /** @var list<int> */
    #[ORM\Column(type: 'json')]
    private array $brandIds;

    /** @var list<array{min_quantity: int, kind: string, value: string}> by quantity */
    #[ORM\Column(type: 'json')]
    private array $tiers;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $startsAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $endsAt;

    /** It applies on top of a sale price, not only to items at their regular price. */
    #[ORM\Column]
    private bool $withSales;

    /** Codes and automatic discounts still come off the price it sets. */
    #[ORM\Column(options: ['default' => true])]
    private bool $withCoupons = true;

    /** It applies on top of the price the other rules give, instead of competing with them. */
    #[ORM\Column(options: ['default' => false])]
    private bool $combines = false;

    /**
     * @param list<int>       $groupIds
     * @param list<int>       $productIds
     * @param list<int>       $categoryIds
     * @param list<int>       $brandIds
     * @param list<PriceTier> $tiers
     */
    public function __construct(string $name, array $tiers, array $groupIds = [], array $productIds = [], array $categoryIds = [], array $brandIds = [], ?\DateTimeImmutable $startsAt = null, ?\DateTimeImmutable $endsAt = null, bool $withSales = false, bool $active = true, bool $withCoupons = true, bool $combines = false)
    {
        $this->change($name, $tiers, $groupIds, $productIds, $categoryIds, $brandIds, $startsAt, $endsAt, $withSales, $active, $withCoupons, $combines);
    }

    /**
     * @param list<int>       $groupIds
     * @param list<int>       $productIds
     * @param list<int>       $categoryIds
     * @param list<int>       $brandIds
     * @param list<PriceTier> $tiers
     *
     * @throws CatalogRuleViolation
     */
    public function change(string $name, array $tiers, array $groupIds = [], array $productIds = [], array $categoryIds = [], array $brandIds = [], ?\DateTimeImmutable $startsAt = null, ?\DateTimeImmutable $endsAt = null, bool $withSales = false, bool $active = true, bool $withCoupons = true, bool $combines = false): void
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > self::MAX_NAME_LENGTH) {
            throw new CatalogRuleViolation('A price rule has a name of up to %d characters.', 'orillacart_invalid_price_rule', values: [self::MAX_NAME_LENGTH]);
        }
        if ($tiers === []) {
            throw new CatalogRuleViolation('A price rule sets a price from 1 item, or from some number of items.', 'orillacart_invalid_price_rule');
        }
        usort($tiers, static fn(PriceTier $a, PriceTier $b): int => $a->minQuantity <=> $b->minQuantity);
        $quantities = array_map(static fn(PriceTier $tier): int => $tier->minQuantity, $tiers);
        if (\count(array_unique($quantities)) !== \count($quantities)) {
            throw new CatalogRuleViolation('Two tiers start at the same quantity.', 'orillacart_invalid_price_rule');
        }
        if ($startsAt !== null && $endsAt !== null && $endsAt <= $startsAt) {
            throw new CatalogRuleViolation('A price rule ends after it starts.', 'orillacart_invalid_price_rule');
        }
        $this->name = $name;
        $this->tiers = array_map(static fn(PriceTier $tier): array => ['min_quantity' => $tier->minQuantity, 'kind' => $tier->kind, 'value' => $tier->value->toFixed()], $tiers);
        $this->groupIds = self::ids($groupIds);
        $this->productIds = self::ids($productIds);
        $this->categoryIds = self::ids($categoryIds);
        $this->brandIds = self::ids($brandIds);
        $this->startsAt = $startsAt;
        $this->endsAt = $endsAt;
        $this->withSales = $withSales;
        $this->active = $active;
        $this->withCoupons = $withCoupons;
        $this->combines = $combines;
    }

    /**
     * @param list<int> $ids
     *
     * @return list<int>
     */
    private static function ids(array $ids): array
    {
        return array_values(array_unique(array_filter($ids, static fn(int $id): bool => $id > 0)));
    }

    public function id(): int
    {
        return $this->id ?? throw new \LogicException('The price rule has no id until it is saved.');
    }

    public function name(): string
    {
        return $this->name;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    /**
     * For whom and what the rule is: its groups, products, categories and brands.
     *
     * @return array{groups: list<int>, products: list<int>, categories: list<int>, brands: list<int>}
     */
    public function scope(): array
    {
        return ['groups' => $this->groupIds, 'products' => $this->productIds, 'categories' => $this->categoryIds, 'brands' => $this->brandIds];
    }

    /** @return list<int> */
    public function groupIds(): array
    {
        return $this->groupIds;
    }

    /** @return list<int> */
    public function productIds(): array
    {
        return $this->productIds;
    }

    /** @return list<int> */
    public function categoryIds(): array
    {
        return $this->categoryIds;
    }

    /** @return list<int> */
    public function brandIds(): array
    {
        return $this->brandIds;
    }

    /** @return list<PriceTier> by quantity */
    public function tiers(): array
    {
        return array_map(static fn(array $tier): PriceTier => new PriceTier($tier['min_quantity'], $tier['kind'], Money::of($tier['value'])), $this->tiers);
    }

    public function startsAt(): ?\DateTimeImmutable
    {
        return $this->startsAt;
    }

    public function endsAt(): ?\DateTimeImmutable
    {
        return $this->endsAt;
    }

    public function goesWithSales(): bool
    {
        return $this->withSales;
    }

    public function goesWithCoupons(): bool
    {
        return $this->withCoupons;
    }

    public function combines(): bool
    {
        return $this->combines;
    }

    /**
     * Whether the rule prices the item for a shopper in these groups now.
     *
     * @param list<int> $shopperGroups
     */
    public function holdsFor(PricedItem $item, array $shopperGroups, \DateTimeImmutable $now): bool
    {
        return $this->active
            && ($this->startsAt === null || $now >= $this->startsAt)
            && ($this->endsAt === null || $now < $this->endsAt)
            && ($this->groupIds === [] || array_intersect($this->groupIds, $shopperGroups) !== [])
            && ($this->withSales || !$item->onSale)
            && ($this->productIds === [] && $this->categoryIds === [] && $this->brandIds === []
                || \in_array($item->productId, $this->productIds, true)
                || array_intersect($item->categoryIds, $this->categoryIds) !== []
                || array_intersect($item->brandIds, $this->brandIds) !== []);
    }

    /** The price for this many items, or null when the rule starts at more. */
    public function priceFor(Money $price, int $quantity): ?Money
    {
        $chosen = null;
        foreach ($this->tiers() as $tier) {
            if ($tier->minQuantity <= $quantity) {
                $chosen = $tier;
            }
        }

        return $chosen?->apply($price);
    }
}
