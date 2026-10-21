<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress\Hooks;

use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductBrandTaxonomy;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductCategoryTaxonomy;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductTagTaxonomy;
use OrillaCart\Shared\WordPress\AsHook;
use OrillaCart\Storefront\Domain\ShopPagesRepository;

/**
 * The pages of a product category, tag or brand show its products as the shop does, not as a blog's list of
 * posts: the shop page's own blocks (its Products block, filters and columns, as the shop laid them out), which
 * then list that one's products; the plugin's Products block where the shop page has none. A category's page
 * leads to those below it first. Block themes get templates for them, which the site editor can change; classic
 * themes the plugin's own (templates/wordpress/product-archive.php). A theme's own template for them takes the
 * place of either.
 */
#[AsHook('init', method: 'templates')]
#[AsHook('template_include', method: 'classic', filter: true, priority: 20)]
#[AsHook(self::CONTENT, method: 'content')]
final readonly class ProductArchives
{
    /** The action the classic template calls for the listing. */
    public const string CONTENT = 'orillacart_product_archive';
    private const array TAXONOMIES = [ProductCategoryTaxonomy::NAME, ProductTagTaxonomy::NAME, ProductBrandTaxonomy::NAME];

    public function __construct(private ShopPagesRepository $pages) {}

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
                'content' => str_replace(['{{below}}', '{{listing}}'], [$taxonomy === ProductCategoryTaxonomy::NAME ? "<!-- wp:orillacart/product-categories {\"align\":\"wide\"} /-->\n" : '', $this->listing()], <<<'HTML'
                    <!-- wp:template-part {"slug":"header","tagName":"header"} /-->
                    <!-- wp:group {"tagName":"main","style":{"spacing":{"margin":{"top":"var:preset|spacing|60"}}},"layout":{"type":"constrained"}} -->
                    <main class="wp-block-group" style="margin-top:var(--wp--preset--spacing--60)">
                        <!-- wp:query-title {"type":"archive","showPrefix":false} /-->
                        <!-- wp:term-description /-->
                        {{below}}{{listing}}
                    </main>
                    <!-- /wp:group -->
                    <!-- wp:template-part {"slug":"footer","tagName":"footer"} /-->
                    HTML),
            ]);
        }
    }

    /** The listing for the classic template: what lies below a category, then the shop's blocks. */
    public function content(): void
    {
        $below = is_tax(ProductCategoryTaxonomy::NAME) ? '<!-- wp:orillacart/product-categories /-->' : '';
        // The blocks escape their own output.
        echo do_blocks($below . $this->listing()); // phpcs:ignore WordPress.Security.EscapeOutput
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

    /** The shop page's blocks when it lists products, published; else the Products block on its own. */
    private function listing(): string
    {
        $id = $this->pages->load()->shop;
        $page = $id === null ? null : get_post($id);
        if ($page instanceof \WP_Post && $page->post_status === 'publish' && has_block('orillacart/products', $page)) {
            return $page->post_content;
        }

        return '<!-- wp:orillacart/products {"align":"wide"} /-->';
    }
}
