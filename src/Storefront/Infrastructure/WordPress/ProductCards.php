<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress;

use OrillaCart\Catalog\Application\ShopCatalog;
use OrillaCart\Checkout\Application\ShownPrices;
use OrillaCart\Reviews\Application\Reviews;

/**
 * Products as the shop's cards show them (storefront/_product_card.html.twig): the offer as it is now for the
 * shopper, its page, image and stars. Products not for show, such as drafts, are left out.
 */
final readonly class ProductCards
{
    public function __construct(
        private ShopCatalog $catalog,
        private ShopLinks $links,
        private Shopper $shopper,
        private Reviews $reviews,
        private ShownPrices $shownPrices,
    ) {}

    /**
     * @param list<int> $ids
     * @param bool      $excerpts with a short description each, for rows
     *
     * @return list<array<string, mixed>> in the order of the ids
     */
    public function cards(array $ids, bool $excerpts = false): array
    {
        $ids = array_values(array_filter($ids, static fn(int $id): bool => get_post_status($id) === 'publish'));
        if ($ids === []) {
            return [];
        }
        $offers = $this->shownPrices->offers($this->catalog->offers($ids, $this->shopper->customerId()), $this->shopper->customerId());
        $urls = $this->links->products($ids);
        $ratings = $this->reviews->ratings($ids);
        $cards = [];
        foreach ($ids as $id) {
            if (!isset($offers[$id])) {
                continue;
            }
            $cards[] = [
                'offer' => $offers[$id],
                'simple' => $offers[$id]->isSimple(),
                'url' => $urls[$id] ?? '',
                'image' => get_the_post_thumbnail($id, 'medium', ['class' => 'orillacart-product-card__image', 'loading' => 'lazy']),
                'rating' => $ratings[$id] ?? null,
                'excerpt' => $excerpts ? self::excerpt($id) : '',
            ];
        }

        return $cards;
    }

    /**
     * The product's excerpt, or the start of its text: its own words, not what the page adds to them (WordPress's
     * own excerpt runs the text through the page's filters, which add the product's price and buttons).
     */
    private static function excerpt(int $id): string
    {
        $post = get_post($id);
        if (!$post instanceof \WP_Post) {
            return '';
        }
        $text = trim($post->post_excerpt) !== '' ? $post->post_excerpt : excerpt_remove_blocks(strip_shortcodes($post->post_content));

        return wp_trim_words(wp_strip_all_tags($text), 30);
    }
}
