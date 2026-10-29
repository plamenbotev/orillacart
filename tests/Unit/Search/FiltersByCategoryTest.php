<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Search;

use OrillaCart\Catalog\Application\ProductLookup;
use OrillaCart\Search\Application\CatalogSearch;
use OrillaCart\Search\Application\Dto\SaveSearchFilter;
use OrillaCart\Search\Application\Dto\SearchFilterView;
use OrillaCart\Search\Application\SearchFilters;
use OrillaCart\Search\Application\ShopSearch;
use OrillaCart\Search\Domain\CategoryFilters;
use OrillaCart\Search\Domain\CategoryFiltersRepository;
use OrillaCart\Search\Domain\SearchFilter;
use OrillaCart\Search\Domain\SearchFilterRepository;
use OrillaCart\Search\Domain\SearchRuleViolation;
use OrillaCart\Shared\I18n\Language;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use OrillaCart\Tests\Fixtures\ImmediateTransactions;
use PHPUnit\Framework\TestCase;

/**
 * Clothing (10) holds Shirts (11); Lamps (20) stands apart. The shop offers Colour, Size and Wattage.
 */
final class FiltersByCategoryTest extends TestCase
{
    private InMemorySearchFilters $repository;
    private InMemoryCategoryFilters $categories;
    private ShopSearch $search;
    private SearchFilters $filters;
    private TestShop $shop;

    protected function setUp(): void
    {
        $this->repository = new InMemorySearchFilters();
        $this->repository->save(new SearchFilter('Colour', 'colour', ['Colour'], 1));
        $this->repository->save(new SearchFilter('Size', 'size', ['Size'], 2));
        $this->repository->save(new SearchFilter('Wattage', 'wattage', ['Wattage'], 3));
        $this->categories = new InMemoryCategoryFilters();
        $this->shop = $shop = new TestShop();
        $this->search = new ShopSearch(self::createStub(CatalogSearch::class), $this->repository, $this->categories, $shop->contentLanguages);
        $this->filters = new SearchFilters($this->repository, new ProductLookup($shop->productRecords, $shop->content), $this->categories, $shop->contentTranslations, new ImmediateTransactions());
    }

    public function testACategoryWithoutAChoiceOffersEveryFilterInTheShopsOrder(): void
    {
        self::assertSame(['colour', 'size', 'wattage'], $this->keys(null));
        self::assertSame(['colour', 'size', 'wattage'], $this->keys(11, 10));
        self::assertNull($this->filters->chosenIn(10));
    }

    public function testACategoryOffersOnlyTheFiltersItChoseInItsOrderAndThoseBelowItTheSame(): void
    {
        $this->filters->choose(10, [2, 1, 999]);

        self::assertSame(['size', 'colour'], $this->keys(10), 'an unknown filter is left out');
        self::assertSame([2, 1], $this->filters->chosenIn(10));
        self::assertSame(['size', 'colour'], $this->keys(11, 10), 'shirts choose nothing: clothing\'s');
        self::assertSame(['colour', 'size', 'wattage'], $this->keys(20), 'lamps: the shop\'s');

        $this->filters->choose(11, []);
        self::assertSame([], $this->keys(11, 10), 'shirts offer none');
        $this->filters->choose(11, null);
        $this->filters->choose(10, null);
        self::assertSame(['colour', 'size', 'wattage'], $this->keys(11, 10), 'back to the shop\'s');
    }

    public function testAFilterLabelIsSavedInTheSitesOtherLanguagesAndGoesWithIt(): void
    {
        $this->shop->contentLanguages->languages = [new Language('en', 'en_US', 'English'), new Language('de', 'de_DE', 'Deutsch')];

        $filter = $this->filters->create(new SaveSearchFilter('Size', 'size', ['Size'], translations: ['de_DE' => 'Größe']));
        self::assertSame(['de_DE' => 'Größe'], $filter->translations);
        $kept = $this->filters->update($filter->id, new SaveSearchFilter('Size', 'size', ['Size', 'Größe']));
        self::assertSame(['de_DE' => 'Größe'], $kept->translations, 'a save without them keeps them');
        $this->shop->languageOfRequest->locale = 'de_DE';
        self::assertSame([$filter->id => 'Größe'], $this->shop->contentTranslations->translated('search_filter', [$filter->id], 'label'));

        $this->filters->delete($filter->id);
        self::assertSame([], $this->shop->translationRecords->all('search_filter', [$filter->id]));
    }

    public function testACategoryOffersAtMostAHundredFilters(): void
    {
        $this->expectException(SearchRuleViolation::class);
        new CategoryFilters(10, range(1, 101));
    }

    /** @return list<string> */
    private function keys(?int $categoryId, int ...$above): array
    {
        return array_map(static fn(SearchFilterView $filter): string => $filter->key, $this->search->filters($categoryId, array_values($above)));
    }
}

final class InMemoryCategoryFilters implements CategoryFiltersRepository
{
    /** @var array<int, CategoryFilters> */
    private array $chosen = [];

    public function ofCategories(array $categoryIds): array
    {
        return array_intersect_key($this->chosen, array_flip($categoryIds));
    }

    public function save(CategoryFilters $filters): void
    {
        $this->chosen[$filters->categoryId()] = $filters;
    }

    public function remove(int $categoryId): void
    {
        unset($this->chosen[$categoryId]);
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
