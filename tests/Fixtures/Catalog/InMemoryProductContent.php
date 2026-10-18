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

    public function termIds(int $productId): array
    {
        return $this->terms[$productId] ?? ['categories' => [], 'brands' => []];
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
}
