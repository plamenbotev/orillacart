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
}
