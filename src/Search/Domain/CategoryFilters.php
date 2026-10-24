<?php

declare(strict_types=1);

namespace OrillaCart\Search\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * The filters a product category offers its own way: only these, in this order, for it and the categories
 * below it that choose none. A category without one offers what the category above it does, or the shop's.
 */
#[ORM\Entity]
#[ORM\Table(name: 'search_category_filters')]
class CategoryFilters
{
    public const int MAX_FILTERS = 100;

    /** @var list<int> */
    #[ORM\Column(type: 'json')]
    private array $filterIds;

    /**
     * @param list<int> $filterIds in their order; none offers no filters
     *
     * @throws SearchRuleViolation
     */
    public function __construct(
        #[ORM\Id]
        #[ORM\Column]
        private int $categoryId,
        array $filterIds,
    ) {
        $this->choose($filterIds);
    }

    public function categoryId(): int
    {
        return $this->categoryId;
    }

    /** @return list<int> in their order */
    public function filterIds(): array
    {
        return $this->filterIds;
    }

    /**
     * @param list<int> $filterIds in their order, each once
     *
     * @throws SearchRuleViolation
     */
    public function choose(array $filterIds): void
    {
        $filterIds = array_values(array_unique($filterIds));
        if (\count($filterIds) > self::MAX_FILTERS || array_filter($filterIds, static fn(int $id): bool => $id <= 0) !== []) {
            throw new SearchRuleViolation('A category offers up to %d of the shop\'s filters.', 'orillacart_invalid_category_filters', values: [self::MAX_FILTERS]);
        }
        $this->filterIds = $filterIds;
    }
}
