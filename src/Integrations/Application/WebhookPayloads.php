<?php

declare(strict_types=1);

namespace OrillaCart\Integrations\Application;

use OrillaCart\Catalog\Application\ProductService;
use OrillaCart\Catalog\Domain\ProductNotFound;
use OrillaCart\Ordering\Application\OrderService;
use OrillaCart\Ordering\Domain\OrderNotFound;

/**
 * What a webhook's event carries, as it is when it is delivered: an order or product as the shop's API shows it,
 * with what changed (the statuses, the amount refunded, the stock before and after). An event of an add-on's
 * carries the data the add-on gave.
 */
final readonly class WebhookPayloads
{
    public function __construct(
        private OrderService $orders,
        private ProductService $products,
        private ViewNormalizer $normalizer,
    ) {}

    /**
     * @param array<array-key, mixed> $arguments the event's, as announced
     *
     * @return array<array-key, mixed>
     */
    public function data(string $topic, array $arguments): array
    {
        $number = is_numeric($arguments['order'] ?? null) ? (int) $arguments['order'] : null;
        $product = is_numeric($arguments['product'] ?? null) ? (int) $arguments['product'] : null;

        return match (true) {
            $topic === WebhookTopics::ORDER_DELETED => ['number' => $number],
            str_starts_with($topic, 'order.') && $number !== null => $this->order($number) + array_diff_key($arguments, ['order' => true]),
            $topic === WebhookTopics::PRODUCT_CHANGED && $product !== null => $this->product($product),
            default => $arguments,
        };
    }

    /** @return array<array-key, mixed> */
    private function order(int $number): array
    {
        try {
            return ['order' => $this->normalizer->normalize($this->orders->order($number))];
        } catch (OrderNotFound) {
            return ['order' => null, 'number' => $number];
        }
    }

    /** @return array<array-key, mixed> */
    private function product(int $id): array
    {
        try {
            return ['product' => $this->normalizer->normalize($this->products->product($id))];
        } catch (ProductNotFound) {
            return ['product' => null, 'id' => $id];
        }
    }
}
