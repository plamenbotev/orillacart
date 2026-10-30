<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Catalog;

use OrillaCart\Catalog\Domain\ProductContent;

/**
 * Product posts by id: name and whether published.
 */
final class InMemoryProductContent implements ProductContent
{
    /**
     * @param array<int, array{string, bool}> $posts id => [name, published]
     */
    public function __construct(private array $posts = []) {}

    /** Variation posts are numbered from here, apart from the products the tests number themselves. */
    private int $nextVariation = 1001;

    /** @var array<int, int> variation id => product id */
    public array $variations = [];

    /** @var array<int, array{categories: list<int>, brands: list<int>}> by product id */
    public array $terms = [];

    public function copy(int $productId): int
    {
        $id = max([...array_keys($this->posts), 0]) + 1;
        $this->posts[$id] = [$this->name($productId) . ' (copy)', false];
        $this->terms[$id] = $this->termIds($productId);

        return $id;
    }

    public function add(int $id, string $name, bool $published = true): void
    {
        $this->posts[$id] = [$name, $published];
    }

    public function exists(int $productId): bool
    {
        return isset($this->posts[$productId]);
    }

    public function isPublished(int $productId): bool
    {
        return $this->posts[$productId][1] ?? false;
    }

    public function name(int $productId): string
    {
        return $this->posts[$productId][0] ?? '';
    }

    public function idsNamed(string $words, int $limit, bool $publishedOnly = false): array
    {
        $ids = array_keys(array_filter($this->posts, static fn(array $post): bool => mb_stripos($post[0], $words) !== false && (!$publishedOnly || $post[1])));
        rsort($ids);

        return \array_slice($ids, 0, $limit);
    }

    public function termIds(int $productId): array
    {
        return $this->terms[$productId] ?? ['categories' => [], 'brands' => []];
    }

    public function productIdsInTerms(array $categoryIds, array $brandIds): array
    {
        $ids = [];
        foreach ($this->terms as $productId => $terms) {
            if (array_intersect($terms['categories'], $categoryIds) !== [] || array_intersect($terms['brands'], $brandIds) !== []) {
                $ids[] = $productId;
            }
        }

        return $ids;
    }

    public function createVariation(int $productId, string $name): int
    {
        $id = $this->nextVariation++;
        $this->variations[$id] = $productId;

        return $id;
    }

    public function deleteVariation(int $variationId): void
    {
        unset($this->variations[$variationId]);
    }

    /** @var array<int, int> copied variation => the one it was copied from */
    public array $copiedVariations = [];

    public function copyVariation(int $fromVariationId, int $toVariationId): void
    {
        $this->copiedVariations[$toVariationId] = $fromVariationId;
    }
}
