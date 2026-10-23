<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress\Hooks;

use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductPostType;
use OrillaCart\Shared\WordPress\AsHook;

/**
 * Product page templates for block themes, without the blog's byline and post navigation. The plugin's product
 * template: image, name, the description and the buying form below it (ProductPanel). Two more a product can
 * choose in the editor's Template panel: a wide image above the rest, and no image column at all (a service, a
 * download). A theme's own single-orillacart_product template, one edited in the site editor, or one made there
 * for one product (single-orillacart_product-{slug}) takes the place of the plugin's.
 */
#[AsHook('init')]
final readonly class ProductTemplate
{
    public const string WIDE = 'orillacart-product-wide';
    public const string TEXT = 'orillacart-product-text';

    private const string HEADER = '<!-- wp:template-part {"slug":"header","tagName":"header"} /-->';
    private const string FOOTER = '<!-- wp:template-part {"slug":"footer","tagName":"footer"} /-->';

    public function __invoke(): void
    {
        if (!\function_exists('register_block_template') || !wp_is_block_theme()) {
            return;
        }
        register_block_template('orillacart//single-' . ProductPostType::NAME, [
            'title' => __('Product', 'orillacart'),
            'description' => __('A product page: its image, name, description and buying form.', 'orillacart'),
            'post_types' => [ProductPostType::NAME],
            'content' => self::page('orillacart-product', <<<'HTML'
                <!-- wp:columns {"align":"wide"} -->
                <div class="wp-block-columns alignwide">
                    <!-- wp:column -->
                    <div class="wp-block-column"><!-- wp:post-featured-image /--></div>
                    <!-- /wp:column -->
                    <!-- wp:column -->
                    <div class="wp-block-column">
                        <!-- wp:post-title {"level":1} /-->
                        <!-- wp:post-terms {"term":"orillacart_category"} /-->
                        <!-- wp:post-content {"layout":{"type":"default"}} /-->
                    </div>
                    <!-- /wp:column -->
                </div>
                <!-- /wp:columns -->
                HTML),
        ]);
        register_block_template('orillacart//' . self::WIDE, [
            'title' => __('Product: wide image', 'orillacart'),
            'description' => __('A product page with its image across the page, then its name, description and buying form.', 'orillacart'),
            'post_types' => [ProductPostType::NAME],
            'content' => self::page('orillacart-product orillacart-product--wide', <<<'HTML'
                <!-- wp:post-featured-image {"align":"wide"} /-->
                <!-- wp:post-title {"level":1} /-->
                <!-- wp:post-terms {"term":"orillacart_category"} /-->
                <!-- wp:post-content {"layout":{"type":"constrained"}} /-->
                HTML),
        ]);
        register_block_template('orillacart//' . self::TEXT, [
            'title' => __('Product: text only', 'orillacart'),
            'description' => __('A product page without an image: its name, description and buying form, for a service or a download.', 'orillacart'),
            'post_types' => [ProductPostType::NAME],
            'content' => self::page('orillacart-product orillacart-product--text', <<<'HTML'
                <!-- wp:post-title {"level":1} /-->
                <!-- wp:post-terms {"term":"orillacart_category"} /-->
                <!-- wp:post-content {"layout":{"type":"constrained"}} /-->
                HTML),
        ]);
    }

    /** The header, the page's main part (with a class for the theme) holding these blocks, and the footer. */
    private static function page(string $class, string $blocks): string
    {
        return implode("\n", [
            self::HEADER,
            '<!-- wp:group {"tagName":"main","className":"' . $class . '","style":{"spacing":{"margin":{"top":"var:preset|spacing|60"}}},"layout":{"type":"constrained"}} -->',
            '<main class="wp-block-group ' . $class . '" style="margin-top:var(--wp--preset--spacing--60)">',
            $blocks,
            '</main>',
            '<!-- /wp:group -->',
            self::FOOTER,
        ]);
    }
}
