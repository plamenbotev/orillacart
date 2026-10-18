<?php

declare(strict_types=1);

namespace OrillaCart\Cart\Domain\Event;

use OrillaCart\Shared\Domain\Event\PublicEvent;

/**
 * A cart line's quantity changed. Announced as the action orillacart_cart_item_changed with the product id,
 * the variation id (null for none), the new quantity, the previous one and the customer (null for a guest).
 */
final readonly class CartItemChanged implements PublicEvent
{
    public function __construct(
        public int $productId,
        public ?int $variationId,
        public int $quantity,
        public int $previous,
        public ?int $customerId,
    ) {}

    public function hookName(): string
    {
        return 'cart_item_changed';
    }

    public function hookArguments(): array
    {
        return [$this->productId, $this->variationId, $this->quantity, $this->previous, $this->customerId];
    }
}
