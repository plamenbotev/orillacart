<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress\Hooks;

use OrillaCart\Catalog\Application\Dto\OfferedVariation;
use OrillaCart\Catalog\Application\ShopCatalog;
use OrillaCart\Catalog\Domain\ProductNotFound;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductPostType;
use OrillaCart\Shared\View\TemplateRenderer;
use OrillaCart\Shared\WordPress\AsHook;
use OrillaCart\Storefront\Infrastructure\WordPress\ShopLinks;

/**
 * Puts the buying form under a product's description on its page, and its price under it in lists of posts,
 * such as category pages. Through the content filter, so classic and block themes both show it.
 */
#[AsHook('the_content', method: 'content', filter: true, priority: 20)]
#[AsHook('get_the_excerpt', method: 'excerpt', filter: true, priority: 20, arguments: 2)]
final readonly class ProductPanel
{
    public function __construct(
        private ShopCatalog $catalog,
        private ShopLinks $links,
        private TemplateRenderer $templates,
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
        try {
            $offer = $this->catalog->offer($id);
        } catch (ProductNotFound) {
            return $content;
        }

        return $content . $this->templates->render('storefront/product.html.twig', [
            'offer' => $offer,
            'variations' => array_map(fn(OfferedVariation $variation): array => [
                'id' => $variation->id,
                'option_ids' => $variation->optionIds,
                'sku' => $variation->sku,
                'for_sale' => $variation->forSale,
                'available' => $variation->available,
                'price' => $this->templates->render('storefront/_variation_price.html.twig', ['variation' => $variation]),
            ], $offer->variations),
            'cart_url' => $this->links->pages()['cart'],
        ]);
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
        $offers = $this->catalog->offers([$id]);

        return isset($offers[$id]) ? $this->templates->render('storefront/_list_price.html.twig', ['offer' => $offers[$id]]) : '';
    }
}
