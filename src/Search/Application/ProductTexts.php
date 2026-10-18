<?php

declare(strict_types=1);

namespace OrillaCart\Search\Application;

use OrillaCart\Search\Application\Dto\ProductText;

/**
 * What the site's content (WordPress posts and terms) says of products.
 */
interface ProductTexts
{
    /**
     * The published products among these, read together.
     *
     * @param list<int> $productIds
     *
     * @return array<int, ProductText> by product id; unpublished ones are left out
     */
    public function describe(array $productIds): array;
}
