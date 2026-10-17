<?php

declare(strict_types=1);

namespace OrillaCart\Cart\Application\Dto;

/**
 * A changed cart, and the token the browser must keep from now on (null when it already has it).
 */
final readonly class CartResult
{
    public function __construct(
        public CartView $cart,
        public ?string $newToken,
    ) {}
}
