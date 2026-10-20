<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Exchange;

use OrillaCart\Exchange\Application\Dto\ProductPost;
use OrillaCart\Exchange\Application\Dto\ProductPostChange;
use OrillaCart\Exchange\Application\ProductPosts;
use OrillaCart\Tests\Fixtures\Catalog\InMemoryProductContent;

/** Products' posts a test gives: a published post with no texts for each product unless it says otherwise. */
final class InMemoryProductPosts implements ProductPosts
{
    /** @var array<int, ProductPost> */
    public array $posts = [];
    /** @var array<int, list<ProductPostChange>> */
    public array $changes = [];

    public function __construct(
        private readonly ?InMemoryProductContent $content = null,
        private int $nextId = 900,
    ) {}

    public function of(array $ids): array
    {
        $found = [];
        foreach ($ids as $id) {
            $found[$id] = $this->posts[$id] ?? new ProductPost('publish', '', '', [], [], []);
        }

        return $found;
    }

    public function create(string $name, string $status): int
    {
        $id = $this->nextId++;
        $this->content?->add($id, $name);
        $this->posts[$id] = new ProductPost($status, '', '', [], [], []);

        return $id;
    }

    public function change(int $id, ProductPostChange $change): void
    {
        $this->changes[$id][] = $change;
    }
}
