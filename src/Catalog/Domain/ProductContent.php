<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Domain;

/**
 * The content side of products, which WordPress keeps as posts: names and whether they are published.
 */
interface ProductContent
{
    /** Whether a product post with this id exists, in any status. */
    public function exists(int $productId): bool;

    public function isPublished(int $productId): bool;

    public function name(int $productId): string;
}
