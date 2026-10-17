<?php

declare(strict_types=1);

namespace OrillaCart\Cart\Application\Dto;

/**
 * What a cart holds, without prices: what checkout reads to price and place an order.
 */
final readonly class CartContents
{
    /**
     * @param list<array{line: int, product_id: int, option_ids: list<int>, quantity: int}> $lines
     */
    public function __construct(
        public int $cartId,
        public array $lines,
    ) {}
}
