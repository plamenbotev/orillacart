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
    /** @var list<int> */
    public array $deleted = [];
    /** Who the work runs as now. */
    public ?int $user = null;
    /** @var array<int, string> the status each post was made with */
    public array $createdAs = [];
    /** @var array<int, ?int> who made each post */
    public array $authors = [];

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
        $this->authors[$id] = $this->user;
        $this->createdAs[$id] = $status;

        return $id;
    }

    public function change(int $id, ProductPostChange $change): void
    {
        $this->changes[$id][] = $change;
        $post = $this->posts[$id] ?? new ProductPost('publish', '', '', [], [], []);
        $this->posts[$id] = new ProductPost($change->status ?? $post->status, $change->description ?? $post->description, $change->shortDescription ?? $post->shortDescription, $change->categories ?? $post->categories, $change->brands ?? $post->brands, $change->tags ?? $post->tags);
    }

    public function delete(int $id): void
    {
        unset($this->posts[$id]);
        $this->deleted[] = $id;
    }

    public function asUser(?int $userId, \Closure $work): mixed
    {
        $was = $this->user;
        $this->user = $userId;
        try {
            return $work();
        } finally {
            $this->user = $was;
        }
    }
}
