<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress;

use OrillaCart\Catalog\Application\ProductTranslations;
use OrillaCart\Catalog\Application\ShopCatalog;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductPostType;
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
        private ProductTranslations $translations,
    ) {}

    /**
     * @param list<int> $ids
     * @param bool      $excerpts with a short description each, for rows
     *
     * @return list<array<string, mixed>> in the order of the ids
     */
    public function cards(array $ids, bool $excerpts = false): array
    {
        self::prime($ids);
        $ids = array_values(array_filter($ids, static fn(int $id): bool => get_post_status($id) === 'publish'));
        if ($ids === []) {
            return [];
        }
        // The text in the shopper's language, from the product's translation (design 51).
        $shown = [];
        foreach ($ids as $id) {
            $shown[$id] = $this->translations->shown($id);
        }
        self::prime(array_values(array_diff($shown, $ids)));
        $pictures = [];
        foreach ($ids as $id) {
            $pictures[$id] = has_post_thumbnail($shown[$id]) ? $shown[$id] : $id;
        }
        self::primePictures(array_values($pictures));
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
                'image' => get_the_post_thumbnail($pictures[$id], 'medium', ['class' => 'orillacart-product-card__image', 'loading' => 'lazy']),
                'rating' => $ratings[$id] ?? null,
                'excerpt' => $excerpts ? self::excerpt($shown[$id]) : '',
            ];
        }

        return $cards;
    }

    /**
     * Reads the products' posts, fields and terms for the whole listing at once: each card would otherwise ask for
     * its post's status, fields and categories one query at a time.
     *
     * @param list<int> $ids
     */
    public static function prime(array $ids): void
    {
        if ($ids === []) {
            return;
        }
        _prime_post_caches($ids, false, false);
        update_postmeta_cache($ids);
        update_object_term_cache($ids, ProductPostType::NAME);
    }

    /**
     * The posts' pictures, with their fields (sizes, alternative text), read at once.
     *
     * @param list<int> $ids posts whose pictures are shown, their fields read already
     */
    private static function primePictures(array $ids): void
    {
        $pictures = [];
        foreach ($ids as $id) {
            $picture = (int) get_post_thumbnail_id($id);
            if ($picture > 0) {
                $pictures[$picture] = $picture;
            }
        }
        if ($pictures !== []) {
            _prime_post_caches(array_values($pictures), false, true);
        }
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
