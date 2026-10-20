<?php

declare(strict_types=1);

namespace OrillaCart\Checkout\Application\Dto;

use OrillaCart\Cart\Application\Dto\CartResult;

/**
 * The cart with an order's items in it again, and the names of those that could not go in.
 */
final readonly class ReorderView
{
    public function __construct(
        public CartResult $result,
        /** @var list<string> */
        public array $missed,
    ) {}
}
