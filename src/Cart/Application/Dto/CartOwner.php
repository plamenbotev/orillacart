<?php

declare(strict_types=1);

namespace OrillaCart\Cart\Application\Dto;

/**
 * Who asks for a cart: the token from the browser's cookie, if any, and the logged-in customer, if any.
 */
final readonly class CartOwner
{
    public function __construct(
        public ?string $token,
        public ?int $customerId = null,
    ) {}
}
