<?php

declare(strict_types=1);

namespace OrillaCart\Search\Application;

use OrillaCart\Search\Application\Dto\ProductText;

/**
 * What the site's content (WordPress posts and terms) says of products.
 */
interface ProductTexts
{
    /** Null unless the product is published. */
    public function describe(int $productId): ?ProductText;
}
