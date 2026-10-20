<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Exchange;

use OrillaCart\Exchange\Application\Dto\ProductPost;
use OrillaCart\Exchange\Application\ProductPosts;

/** Products' posts a test gives: a published post with no texts for each product unless it says otherwise. */
final class InMemoryProductPosts implements ProductPosts
{
    /** @var array<int, ProductPost> */
    public array $posts = [];

    public function of(array $ids): array
    {
        $found = [];
        foreach ($ids as $id) {
            $found[$id] = $this->posts[$id] ?? new ProductPost('publish', '', '', [], [], []);
        }

        return $found;
    }
}
