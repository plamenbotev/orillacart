<?php

declare(strict_types=1);

namespace OrillaCart\Cart\Domain\Event;

use OrillaCart\Shared\Domain\Event\PublicEvent;

/**
 * An item went into a cart, or more of it. Announced as the action orillacart_cart_item_added with the product id,
 * the variation id (null for none), how many were added and the customer (null for a guest).
 */
final readonly class CartItemAdded implements PublicEvent
{
    public function __construct(
        public int $productId,
        public ?int $variationId,
        public int $quantity,
        public ?int $customerId,
    ) {}

    public function hookName(): string
    {
        return 'cart_item_added';
    }

    public function hookArguments(): array
    {
        return [$this->productId, $this->variationId, $this->quantity, $this->customerId];
    }
}
