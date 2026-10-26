<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress\Hooks;

use OrillaCart\Catalog\Application\ShopCatalog;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductPostType;
use OrillaCart\Checkout\Application\ShownPrices;
use OrillaCart\Shared\View\TemplateRenderer;
use OrillaCart\Shared\WordPress\AsHook;
use OrillaCart\Storefront\Infrastructure\WordPress\ProductParts;
use OrillaCart\Storefront\Infrastructure\WordPress\Shopper;

/**
 * Puts the buying form, the products to buy instead or besides, and the reviews under a product's description on
 * its page, where the page's block template does not show them as blocks (classic themes, a template without the
 * Add to cart block); and its price under it in lists of posts, such as category pages. Through the content
 * filter, so classic and block themes both show it.
 */
#[AsHook('the_content', method: 'content', filter: true, priority: 20)]
#[AsHook('get_the_excerpt', method: 'excerpt', filter: true, priority: 20, arguments: 2)]
final readonly class ProductPanel
{
    public function __construct(
        private ShopCatalog $catalog,
        private TemplateRenderer $templates,
        private Shopper $shopper,
        private ShownPrices $shownPrices,
        private ProductParts $parts,
    ) {}

    public function content(string $content): string
    {
        $id = get_the_ID();
        if (!\is_int($id) || get_post_type($id) !== ProductPostType::NAME || doing_filter('get_the_excerpt')) {
            return $content;
        }
        if (!is_singular(ProductPostType::NAME) || $id !== get_queried_object_id()) {
            return $content . $this->price($id);
        }
        if (ProductParts::templateHasBlocks()) {
            return $content;
        }

        return $content . $this->parts->whole($id);
    }

    public function excerpt(string $excerpt, ?\WP_Post $post = null): string
    {
        if ($post === null || $post->post_type !== ProductPostType::NAME || is_singular(ProductPostType::NAME)) {
            return $excerpt;
        }

        return $excerpt . ' ' . wp_strip_all_tags($this->price($post->ID));
    }

    private function price(int $id): string
    {
        $offers = $this->shownPrices->offers($this->catalog->offers([$id], $this->shopper->customerId()), $this->shopper->customerId());

        return isset($offers[$id]) ? $this->templates->render('storefront/_list_price.html.twig', ['offer' => $offers[$id]]) : '';
    }
}
