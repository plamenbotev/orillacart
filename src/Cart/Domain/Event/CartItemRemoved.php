<?php

declare(strict_types=1);

namespace OrillaCart\Cart\Domain\Event;

use OrillaCart\Shared\Domain\Event\PublicEvent;

/**
 * A line left a cart. Announced as the action orillacart_cart_item_removed with the product id, the variation id
 * (null for none), the quantity it had and the customer (null for a guest).
 */
final readonly class CartItemRemoved implements PublicEvent
{
    public function __construct(
        public int $productId,
        public ?int $variationId,
        public int $quantity,
        public ?int $customerId,
    ) {}

    public function hookName(): string
    {
        return 'cart_item_removed';
    }

    public function hookArguments(): array
    {
        return [$this->productId, $this->variationId, $this->quantity, $this->customerId];
    }
}
