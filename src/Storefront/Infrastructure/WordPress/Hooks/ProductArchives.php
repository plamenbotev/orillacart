<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress\Hooks;

use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductBrandTaxonomy;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductCategoryTaxonomy;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductTagTaxonomy;
use OrillaCart\Shared\WordPress\AsHook;

/**
 * The pages of a product category, tag or brand show its products as the shop does (the Products block, with
 * its filters, sorting and pages), not as a blog's list of posts. Block themes get templates for them, which the
 * site editor can change; classic themes the plugin's own (templates/wordpress/product-archive.php). A theme's
 * own template for them takes the place of either.
 */
#[AsHook('init', method: 'templates')]
#[AsHook('template_include', method: 'classic', filter: true, priority: 20)]
final readonly class ProductArchives
{
    private const array TAXONOMIES = [ProductCategoryTaxonomy::NAME, ProductTagTaxonomy::NAME, ProductBrandTaxonomy::NAME];

    public function templates(): void
    {
        if (!\function_exists('register_block_template') || !wp_is_block_theme()) {
            return;
        }
        $titles = [
            ProductCategoryTaxonomy::NAME => [__('Product category', 'orillacart'), __('The products of a category, with the shop’s filters and sorting.', 'orillacart')],
            ProductTagTaxonomy::NAME => [__('Product tag', 'orillacart'), __('The products with a tag, with the shop’s filters and sorting.', 'orillacart')],
            ProductBrandTaxonomy::NAME => [__('Product brand', 'orillacart'), __('The products of a brand, with the shop’s filters and sorting.', 'orillacart')],
        ];
        foreach ($titles as $taxonomy => [$title, $description]) {
            register_block_template('orillacart//taxonomy-' . $taxonomy, [
                'title' => $title,
                'description' => $description,
                'content' => <<<'HTML'
                    <!-- wp:template-part {"slug":"header","tagName":"header"} /-->
                    <!-- wp:group {"tagName":"main","style":{"spacing":{"margin":{"top":"var:preset|spacing|60"}}},"layout":{"type":"constrained"}} -->
                    <main class="wp-block-group" style="margin-top:var(--wp--preset--spacing--60)">
                        <!-- wp:query-title {"type":"archive","showPrefix":false} /-->
                        <!-- wp:term-description /-->
                        <!-- wp:orillacart/products {"align":"wide"} /-->
                    </main>
                    <!-- /wp:group -->
                    <!-- wp:template-part {"slug":"footer","tagName":"footer"} /-->
                    HTML,
            ]);
        }
    }

    public function classic(string $template): string
    {
        if (wp_is_block_theme() || !is_tax(self::TAXONOMIES)) {
            return $template;
        }
        $term = get_queried_object();
        if (!$term instanceof \WP_Term) {
            return $template;
        }
        $own = locate_template(["taxonomy-{$term->taxonomy}-{$term->slug}.php", "taxonomy-{$term->taxonomy}.php"]);

        return $own !== '' ? $own : \dirname(__DIR__, 5) . '/templates/wordpress/product-archive.php';
    }
}
