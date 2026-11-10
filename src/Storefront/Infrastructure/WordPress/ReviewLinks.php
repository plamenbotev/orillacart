<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress;

use OrillaCart\Ordering\Application\Dto\OrderLineView;
use OrillaCart\Ordering\Application\Dto\OrderView;
use OrillaCart\Reviews\Application\Reviews;
use OrillaCart\Reviews\Application\ReviewTokens;

/**
 * Links from a paid order's receipt to the review form of each product in it. Each carries a token for reviewing
 * that product alone (ReviewTokens), which says who bought it, so a guest's review is a buyer's too; passing the
 * link on hands over nothing else of the order.
 */
final readonly class ReviewLinks
{
    public const string QUERY = 'orillacart_review';

    public function __construct(
        private Reviews $reviews,
        private ShopLinks $links,
        private ReviewTokens $tokens,
    ) {}

    /**
     * @return array<int, string> by product id; none while the order is unpaid, or the shop takes no reviews
     */
    public function forOrder(OrderView $order): array
    {
        if (!$this->reviews->shown() || $order->paidAt === null || \in_array($order->status, ['cancelled', 'failed'], true)) {
            return [];
        }
        $urls = $this->links->products(array_values(array_unique(array_map(static fn(OrderLineView $line): int => $line->productId, $order->lines))));
        $linked = [];
        foreach ($urls as $productId => $url) {
            $linked[$productId] = add_query_arg(self::QUERY, $this->tokens->issue($order->number, $productId), $url) . '#reviews';
        }

        return $linked;
    }
}
