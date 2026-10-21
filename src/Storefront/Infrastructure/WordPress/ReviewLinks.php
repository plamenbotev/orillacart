<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress;

use OrillaCart\Ordering\Application\Dto\OrderLineView;
use OrillaCart\Ordering\Application\Dto\OrderView;
use OrillaCart\Reviews\Application\Reviews;

/**
 * Links from a paid order's receipt to the review form of each product in it. The link carries the receipt's
 * token, which says who bought what, so a guest's review is a buyer's too.
 */
final readonly class ReviewLinks
{
    public const string QUERY = 'orillacart_review';

    public function __construct(
        private Reviews $reviews,
        private ShopLinks $links,
    ) {}

    /**
     * @return array<int, string> by product id; none while the order is unpaid, or the shop takes no reviews
     */
    public function forOrder(OrderView $order, string $receipt): array
    {
        if (!$this->reviews->shown() || $order->paidAt === null || \in_array($order->status, ['cancelled', 'failed'], true)) {
            return [];
        }
        $urls = $this->links->products(array_values(array_unique(array_map(static fn(OrderLineView $line): int => $line->productId, $order->lines))));

        return array_map(static fn(string $url): string => add_query_arg(self::QUERY, $receipt, $url) . '#reviews', $urls);
    }
}
