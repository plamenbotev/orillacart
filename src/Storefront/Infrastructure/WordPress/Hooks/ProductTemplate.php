<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress\Hooks;

use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductPostType;
use OrillaCart\Shared\WordPress\AsHook;

/**
 * A product page template for block themes, without the blog's byline and post navigation: image, name, the
 * description and the buying form below it (ProductPanel). A theme's own single-orillacart_product template,
 * or one edited in the site editor, takes its place.
 */
#[AsHook('init')]
final readonly class ProductTemplate
{
    public function __invoke(): void
    {
        if (!\function_exists('register_block_template') || !wp_is_block_theme()) {
            return;
        }
        register_block_template('orillacart//single-' . ProductPostType::NAME, [
            'title' => __('Product', 'orillacart'),
            'description' => __('A product page: its image, name, description and buying form.', 'orillacart'),
            'post_types' => [ProductPostType::NAME],
            'content' => <<<'HTML'
                <!-- wp:template-part {"slug":"header","tagName":"header"} /-->
                <!-- wp:group {"tagName":"main","style":{"spacing":{"margin":{"top":"var:preset|spacing|60"}}},"layout":{"type":"constrained"}} -->
                <main class="wp-block-group" style="margin-top:var(--wp--preset--spacing--60)">
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
                </main>
                <!-- /wp:group -->
                <!-- wp:template-part {"slug":"footer","tagName":"footer"} /-->
                HTML,
        ]);
    }
}
