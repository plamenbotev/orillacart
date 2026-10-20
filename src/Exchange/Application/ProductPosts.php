<?php

declare(strict_types=1);

namespace OrillaCart\Exchange\Application;

use OrillaCart\Exchange\Application\Dto\ProductPost;
use OrillaCart\Exchange\Application\Dto\ProductPostChange;

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

    /**
     * A new product's post, for a row the import adds.
     *
     * @return int the product's id
     */
    public function create(string $name, string $status): int;

    /** Changes what the change names; the rest stays. Categories missing are made, with their parents. */
    public function change(int $id, ProductPostChange $change): void;
}
