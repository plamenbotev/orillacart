<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Infrastructure\WordPress;

use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductBrandTaxonomy;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductCategoryTaxonomy;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductPostType;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductTagTaxonomy;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\VariationPostType;
use OrillaCart\Shared\Lifecycle\LifecycleListener;
use OrillaCart\Shared\Lifecycle\OutsideTheSite;
use OrillaCart\Shared\Lifecycle\RemovesShopData;

/**
 * Deletes the product posts and their terms when the plugin is deleted. The Catalog tables go with the
 * plugin's other tables.
 */
final readonly class CatalogContentCleanup implements LifecycleListener, RemovesShopData, OutsideTheSite
{
    public function __construct(
        private ProductCategoryTaxonomy $categories,
        private ProductBrandTaxonomy $brands,
        private ProductTagTaxonomy $tags,
    ) {}

    public function activate(): void {}

    public function upgrade(?string $fromVersion): void {}

    public function deactivate(): void {}

    public function uninstall(): void
    {
        $posts = get_posts(['post_type' => [ProductPostType::NAME, VariationPostType::NAME], 'post_status' => array_keys(get_post_stati()), 'numberposts' => -1, 'fields' => 'ids']);
        foreach ($posts as $postId) {
            wp_delete_post((int) $postId, true);
        }

        foreach ([$this->categories, $this->brands, $this->tags] as $taxonomy) {
            // The plugin is not running during uninstall, so its taxonomies are not registered.
            if (!taxonomy_exists($taxonomy->name())) {
                register_taxonomy($taxonomy->name(), $taxonomy->objectTypes(), $taxonomy->arguments());
            }
            $terms = get_terms(['taxonomy' => $taxonomy->name(), 'hide_empty' => false, 'fields' => 'ids']);
            foreach (\is_array($terms) ? $terms : [] as $termId) {
                wp_delete_term((int) $termId, $taxonomy->name());
            }
        }
    }
}
