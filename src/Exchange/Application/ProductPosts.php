<?php

declare(strict_types=1);

namespace OrillaCart\Exchange\Application;

use OrillaCart\Exchange\Application\Dto\ProductPost;

/**
 * Products as WordPress keeps them: the post, its texts and its terms.
 */
interface ProductPosts
{
    /**
     * @param list<int> $ids
     *
     * @return array<int, ProductPost> by id; a product without its post is left out
     */
    public function of(array $ids): array;
}
