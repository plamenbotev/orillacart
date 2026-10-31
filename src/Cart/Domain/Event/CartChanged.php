<?php

declare(strict_types=1);

namespace OrillaCart\Cart\Domain\Event;

/**
 * What a cart holds changed: its items, codes, or the owner it belongs to. The items discounts give are kept in
 * step with it as it is now. Not announced to WordPress.
 */
final readonly class CartChanged
{
    public function __construct(
        public int $cartId,
        public ?int $customerId,
    ) {}
}
