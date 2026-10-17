<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Domain;

/**
 * The content side of products and their variations, which WordPress keeps as posts: names, whether they are
 * published, and the posts of variations, which are children of their product's.
 */
interface ProductContent
{
    /** Whether a product post with this id exists, in any status. */
    public function exists(int $productId): bool;

    public function isPublished(int $productId): bool;

    public function name(int $productId): string;

    /**
     * Makes the post of a new variation of the product.
     *
     * @return int its id, which the variation shares
     */
    public function createVariation(int $productId, string $name): int;

    /** Removes a variation's post, with nothing left in the trash. */
    public function deleteVariation(int $variationId): void;
}
