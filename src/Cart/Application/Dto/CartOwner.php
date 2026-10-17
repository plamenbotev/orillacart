<?php

declare(strict_types=1);

namespace OrillaCart\Cart\Application\Dto;

/**
 * Who asks for a cart: the token from the browser's cookie, if any, and the logged-in customer, if any.
 */
final readonly class CartOwner
{
    /** The browser cookie that holds the cart token. */
    public const string COOKIE = 'orillacart_cart';

    public function __construct(
        public ?string $token,
        public ?int $customerId = null,
    ) {}

    /**
     * The owner from the cookie's value; a value that is not a token the shop hands out is ignored.
     */
    public static function fromCookie(mixed $value, ?int $customerId): self
    {
        return new self(\is_string($value) && preg_match('/^[a-f0-9]{64}$/', $value) === 1 ? $value : null, $customerId);
    }
}
