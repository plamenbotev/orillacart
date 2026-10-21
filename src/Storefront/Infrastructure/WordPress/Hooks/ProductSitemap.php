<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress\Hooks;

use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductPostType;
use OrillaCart\Catalog\Infrastructure\WordPress\Hooks\ProductGallery;
use OrillaCart\Shared\WordPress\AsHook;
use OrillaCart\Storefront\Infrastructure\WordPress\Sitemaps\ImageSitemapRenderer;

/**
 * Products in WordPress's own sitemap (wp-sitemap-posts-orillacart_product-1.xml) say when they last changed and
 * list their images, the featured one first. SEO plugins that write their own sitemaps turn WordPress's off.
 */
#[AsHook('wp_sitemaps_init', method: 'renderer')]
#[AsHook('wp_sitemaps_posts_entry', method: 'entry', filter: true, arguments: 3)]
final readonly class ProductSitemap
{
    public function renderer(\WP_Sitemaps $sitemaps): void
    {
        if ($sitemaps->renderer::class === \WP_Sitemaps_Renderer::class) {
            $sitemaps->renderer = new ImageSitemapRenderer();
        }
    }

    /**
     * @param array<string, mixed> $entry
     *
     * @return array<string, mixed>
     */
    public function entry(array $entry, \WP_Post $post, string $postType): array
    {
        if ($postType !== ProductPostType::NAME) {
            return $entry;
        }
        $entry['lastmod'] = gmdate(DATE_W3C, (int) strtotime($post->post_modified_gmt . ' UTC'));
        $images = [];
        foreach (array_unique([(int) get_post_thumbnail_id($post), ...ProductGallery::of($post->ID)]) as $image) {
            $source = $image > 0 ? wp_get_attachment_image_url($image, 'full') : false;
            if (\is_string($source)) {
                $images[] = $source;
            }
        }
        if ($images !== []) {
            $entry['images'] = $images;
        }

        return $entry;
    }
}
