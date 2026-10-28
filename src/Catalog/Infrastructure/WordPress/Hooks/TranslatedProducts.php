<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Infrastructure\WordPress\Hooks;

use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductBrandTaxonomy;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductCategoryTaxonomy;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductPostType;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductTagTaxonomy;
use OrillaCart\Shared\WordPress\AsHook;

/**
 * Polylang translates products, categories, brands and tags without being set to. A translation is the product's
 * text only (design 51): variations, whose names come from their options, are not translated as posts. WPML reads
 * the same from wpml-config.xml in the plugin's folder.
 */
#[AsHook('pll_get_post_types', filter: true, method: 'postTypes', arguments: 2)]
#[AsHook('pll_get_taxonomies', filter: true, method: 'taxonomies', arguments: 2)]
final readonly class TranslatedProducts
{
    /**
     * @param array<string, string> $postTypes
     *
     * @return array<string, string>
     */
    public function postTypes(mixed $postTypes, bool $isSettings = false): array
    {
        return [...(\is_array($postTypes) ? $postTypes : []), ProductPostType::NAME => ProductPostType::NAME];
    }

    /**
     * @param array<string, string> $taxonomies
     *
     * @return array<string, string>
     */
    public function taxonomies(mixed $taxonomies, bool $isSettings = false): array
    {
        $own = [ProductCategoryTaxonomy::NAME, ProductBrandTaxonomy::NAME, ProductTagTaxonomy::NAME];

        return [...(\is_array($taxonomies) ? $taxonomies : []), ...array_combine($own, $own)];
    }
}
