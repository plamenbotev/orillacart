<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress;

use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductPostType;
use OrillaCart\Shared\WordPress\BlockContext;

/**
 * The product a product block shows: the one its Product setting names (a landing page's spotlight), else the
 * post it renders for (its page, a Query Loop, a product card). In the editor's preview of a template, which has
 * no product, the latest one, so the template looks like a product page.
 */
final readonly class ProductForBlock
{
    /**
     * @param array<string, mixed> $attributes
     */
    public static function id(array $attributes, BlockContext $context): ?int
    {
        $chosen = $attributes['productId'] ?? 0;
        if (\is_int($chosen) && $chosen > 0) {
            return get_post_type($chosen) === ProductPostType::NAME ? $chosen : null;
        }
        if ($context->postId !== null && get_post_type($context->postId) === ProductPostType::NAME) {
            return $context->postId;
        }

        return ($attributes['preview'] ?? false) === true ? self::latest() : null;
    }

    /** The latest published product, for those who edit the site; null for anyone else or a shop without one. */
    private static function latest(): ?int
    {
        if (!(\defined('REST_REQUEST') && REST_REQUEST) || !current_user_can('edit_posts')) {
            return null;
        }
        $ids = get_posts(['post_type' => ProductPostType::NAME, 'post_status' => 'publish', 'numberposts' => 1, 'fields' => 'ids', 'orderby' => 'date', 'order' => 'DESC']);

        return \is_int($ids[0] ?? null) ? $ids[0] : null;
    }
}
