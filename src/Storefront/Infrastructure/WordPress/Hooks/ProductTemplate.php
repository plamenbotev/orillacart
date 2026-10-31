<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress\Hooks;

use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductPostType;
use OrillaCart\Shared\WordPress\AsHook;

/**
 * Product page templates for block themes, without the blog's byline and post navigation, made of the product
 * blocks (design 50), which the site editor moves, sets and removes: breadcrumbs; the pictures beside the name,
 * stars, categories, price, short description, buying form and delivery estimate; the description; the upsells,
 * related and recently viewed products; the reviews. Two more a product can choose in the editor's Template panel:
 * the pictures across the page above the rest, and no pictures at all (a service, a download). A theme's own
 * single-orillacart_product template, one edited in the site editor, or one made there for one product
 * (single-orillacart_product-{slug}) takes the place of the plugin's.
 */
#[AsHook('init')]
final readonly class ProductTemplate
{
    public const string WIDE = 'orillacart-product-wide';
    public const string TEXT = 'orillacart-product-text';

    private const string HEADER = '<!-- wp:template-part {"slug":"header","tagName":"header"} /-->';
    private const string FOOTER = '<!-- wp:template-part {"slug":"footer","tagName":"footer"} /-->';
    private const string BUY = <<<'HTML'
        <!-- wp:post-title {"level":1} /-->
        <!-- wp:orillacart/product-rating /-->
        <!-- wp:post-terms {"term":"orillacart_category"} /-->
        <!-- wp:orillacart/product-price /-->
        <!-- wp:orillacart/product-excerpt /-->
        <!-- wp:orillacart/add-to-cart /-->
        <!-- wp:orillacart/delivery-estimate /-->
        HTML;
    private const string AFTER = <<<'HTML'
        <!-- wp:orillacart/related-products {"kind":"upsells","align":"wide"} /-->
        <!-- wp:orillacart/related-products {"align":"wide"} /-->
        <!-- wp:orillacart/recently-viewed {"align":"wide"} /-->
        <!-- wp:orillacart/product-reviews /-->
        HTML;

    public function __invoke(): void
    {
        if (!\function_exists('register_block_template') || !wp_is_block_theme()) {
            return;
        }
        register_block_template('orillacart//single-' . ProductPostType::NAME, [
            'title' => __('Product', 'orillacart'),
            'description' => __('A product page: its pictures beside its name, price and buying form, then its description, the products beside it and its reviews.', 'orillacart'),
            'post_types' => [ProductPostType::NAME],
            'content' => self::page('orillacart-product-page', <<<'HTML'
                <!-- wp:columns {"align":"wide"} -->
                <div class="wp-block-columns alignwide">
                    <!-- wp:column -->
                    <div class="wp-block-column"><!-- wp:orillacart/product-gallery /--></div>
                    <!-- /wp:column -->
                    <!-- wp:column -->
                    <div class="wp-block-column">
                        BUY
                    </div>
                    <!-- /wp:column -->
                </div>
                <!-- /wp:columns -->
                <!-- wp:post-content {"layout":{"type":"constrained"}} /-->
                AFTER
                HTML),
        ]);
        register_block_template('orillacart//' . self::WIDE, [
            'title' => __('Product: wide image', 'orillacart'),
            'description' => __('A product page with its pictures across the page, then its name, price, buying form and description.', 'orillacart'),
            'post_types' => [ProductPostType::NAME],
            'content' => self::page('orillacart-product-page orillacart-product-page--wide', <<<'HTML'
                <!-- wp:orillacart/product-gallery {"align":"wide"} /-->
                BUY
                <!-- wp:post-content {"layout":{"type":"constrained"}} /-->
                AFTER
                HTML),
        ]);
        register_block_template('orillacart//' . self::TEXT, [
            'title' => __('Product: text only', 'orillacart'),
            'description' => __('A product page without pictures: its name, price, buying form and description, for a service or a download.', 'orillacart'),
            'post_types' => [ProductPostType::NAME],
            'content' => self::page('orillacart-product-page orillacart-product-page--text', <<<'HTML'
                BUY
                <!-- wp:post-content {"layout":{"type":"constrained"}} /-->
                AFTER
                HTML),
        ]);
    }

    /**
     * The header, the page's main part (with a class for the theme) holding the breadcrumbs and these blocks, and
     * the footer. BUY: the name, stars, categories, price, short description, buying form and delivery estimate;
     * AFTER: the upsells, related and recently viewed products, and the reviews.
     */
    private static function page(string $class, string $blocks): string
    {
        return implode("\n", [
            self::HEADER,
            '<!-- wp:group {"tagName":"main","className":"' . $class . '","style":{"spacing":{"margin":{"top":"var:preset|spacing|60"}}},"layout":{"type":"constrained"}} -->',
            '<main class="wp-block-group ' . $class . '" style="margin-top:var(--wp--preset--spacing--60)">',
            '<!-- wp:orillacart/breadcrumbs /-->',
            str_replace(['BUY', 'AFTER'], [self::BUY, self::AFTER], $blocks),
            '</main>',
            '<!-- /wp:group -->',
            self::FOOTER,
        ]);
    }
}
