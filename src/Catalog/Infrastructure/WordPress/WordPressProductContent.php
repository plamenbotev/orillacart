<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Infrastructure\WordPress;

use OrillaCart\Catalog\Domain\ProductContent;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductPostType;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\VariationPostType;

final readonly class WordPressProductContent implements ProductContent
{
    public function exists(int $productId): bool
    {
        return get_post_type($productId) === ProductPostType::NAME;
    }

    public function isPublished(int $productId): bool
    {
        return $this->exists($productId) && get_post_status($productId) === 'publish';
    }

    public function name(int $productId): string
    {
        // Plain text: the title filters add HTML entities, and templates and emails escape names themselves.
        return $this->exists($productId) ? wp_strip_all_tags(html_entity_decode(get_the_title($productId), ENT_QUOTES | ENT_HTML5, 'UTF-8')) : '';
    }

    public function createVariation(int $productId, string $name): int
    {
        $id = wp_insert_post([
            'post_type' => VariationPostType::NAME,
            'post_parent' => $productId,
            // Private: WordPress's REST API shows published posts of any type to anyone, which would show the
            // variations of draft products. The storefront reads them on the server.
            'post_status' => 'private',
            'post_title' => $name,
            'comment_status' => 'closed',
        ], true);
        if ($id instanceof \WP_Error) {
            throw new \RuntimeException('The variation\'s post could not be made: ' . $id->get_error_message());
        }

        return $id;
    }

    public function deleteVariation(int $variationId): void
    {
        if (get_post_type($variationId) === VariationPostType::NAME) {
            wp_delete_post($variationId, true);
        }
    }
}
