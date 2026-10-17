<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Domain\Event;

use OrillaCart\Shared\Domain\Event\PublicEvent;

/**
 * A product's price, options, variations or other commerce details changed.
 * Announced as the action orillacart_product_changed with the product id.
 */
final readonly class ProductChanged implements PublicEvent
{
    public function __construct(public int $productId) {}

    public function hookName(): string
    {
        return 'product_changed';
    }

    /**
     * @return list<int>
     */
    public function hookArguments(): array
    {
        return [$this->productId];
    }
}
