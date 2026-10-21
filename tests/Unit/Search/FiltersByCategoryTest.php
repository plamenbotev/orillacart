<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Search;

use OrillaCart\Catalog\Application\ProductLookup;
use OrillaCart\Search\Application\CatalogSearch;
use OrillaCart\Search\Application\Dto\SearchFilterView;
use OrillaCart\Search\Application\SearchFilters;
use OrillaCart\Search\Application\ShopSearch;
use OrillaCart\Search\Domain\SearchFilter;
use OrillaCart\Search\Domain\SearchFilterRepository;
use OrillaCart\Search\Domain\SearchRuleViolation;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use PHPUnit\Framework\TestCase;

/**
 * Clothing (10) holds Shirts (11); Lamps (20) stands apart. Size shows in clothing, Wattage in lamps, Colour
 * everywhere.
 */
final class FiltersByCategoryTest extends TestCase
{
    private InMemorySearchFilters $repository;
    private ShopSearch $search;

    protected function setUp(): void
    {
        $this->repository = new InMemorySearchFilters();
        $this->repository->save(new SearchFilter('Colour', 'colour', ['Colour'], 1));
        $this->repository->save(new SearchFilter('Size', 'size', ['Size'], 2, [10]));
        $this->repository->save(new SearchFilter('Wattage', 'wattage', ['Wattage'], 3, [20]));
        $this->search = new ShopSearch(self::createStub(CatalogSearch::class), $this->repository);
    }

    public function testAFilterShowsInTheCategoriesChosenAndBelowThem(): void
    {
        self::assertSame(['colour'], $this->keys(null), 'the whole shop: only those for every category');
        self::assertSame(['colour', 'size'], $this->keys(10));
        self::assertSame(['colour', 'size'], $this->keys(11, 10), 'shirts, below clothing');
        self::assertSame(['colour', 'wattage'], $this->keys(20));
    }

    public function testACategoryOrdersItsFiltersAndThoseBelowItTakeThatOrder(): void
    {
        $shop = new TestShop();
        $filters = new SearchFilters($this->repository, new ProductLookup($shop->productRecords, $shop->content));
        $filters->order(10, [2 => 0]);

        self::assertSame(['size', 'colour'], $this->keys(10), 'size first, colour after in the shop\'s order');
        self::assertSame(['size', 'colour'], $this->keys(11, 10), 'shirts order nothing: clothing\'s order');
        self::assertSame([2 => 0], $filters->orderIn(10));
        self::assertSame([], $filters->orderIn(11));

        $filters->order(11, [1 => 0, 2 => 1]);
        self::assertSame(['colour', 'size'], $this->keys(11, 10), 'their own');
        $filters->order(10, []);
        self::assertSame(['colour', 'size'], $this->keys(10), 'back to the shop\'s order');
    }

    public function testAFilterShowsInAtMostAHundredCategories(): void
    {
        $this->expectException(SearchRuleViolation::class);
        new SearchFilter('Size', 'size', ['Size'], 0, range(1, 101));
    }

    /** @return list<string> */
    private function keys(?int $categoryId, int ...$above): array
    {
        return array_map(static fn(SearchFilterView $filter): string => $filter->key, $this->search->filters($categoryId, array_values($above)));
    }
}

final class InMemorySearchFilters implements SearchFilterRepository
{
    /** @var array<int, SearchFilter> */
    private array $filters = [];

    public function all(): array
    {
        $all = array_values($this->filters);
        usort($all, static fn(SearchFilter $a, SearchFilter $b): int => [$a->position(), $a->label()] <=> [$b->position(), $b->label()]);

        return $all;
    }

    public function find(int $id): ?SearchFilter
    {
        return $this->filters[$id] ?? null;
    }

    public function isKeyTaken(string $key, ?int $exceptId = null): bool
    {
        return false;
    }

    public function save(SearchFilter $filter): void
    {
        $id = new \ReflectionProperty(SearchFilter::class, 'id');
        if ($id->getValue($filter) === null) {
            $id->setValue($filter, \count($this->filters) + 1);
        }
        $this->filters[$filter->id()] = $filter;
    }

    public function remove(SearchFilter $filter): void
    {
        unset($this->filters[$filter->id()]);
    }
}
