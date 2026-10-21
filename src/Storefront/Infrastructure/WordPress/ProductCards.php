<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress;

use OrillaCart\Catalog\Application\ShopCatalog;
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
    ) {}

    /**
     * @param list<int> $ids
     *
     * @return list<array<string, mixed>> in the order of the ids
     */
    public function cards(array $ids): array
    {
        $ids = array_values(array_filter($ids, static fn(int $id): bool => get_post_status($id) === 'publish'));
        if ($ids === []) {
            return [];
        }
        $offers = $this->catalog->offers($ids, $this->shopper->customerId());
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
            ];
        }

        return $cards;
    }
}
