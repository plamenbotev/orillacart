<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Infrastructure\WordPress\Hooks;

use OrillaCart\Catalog\Application\ProductService;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductPostType;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\VariationPostType;
use OrillaCart\Shared\WordPress\AsHook;

/**
 * When a product post is deleted for good (not trashed), its commerce details go with it.
 */
#[AsHook('before_delete_post', arguments: 2)]
final readonly class ForgetDeletedProducts
{
    public function __construct(private ProductService $products) {}

    public function __invoke(int $postId, \WP_Post $post): void
    {
        match ($post->post_type) {
            ProductPostType::NAME => $this->products->delete($postId),
            VariationPostType::NAME => $this->products->forgetVariation($postId),
            default => null,
        };
    }
}
