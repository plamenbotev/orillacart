<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Cart;

/**
 * Why the cart cannot be ordered as it is, in words for the customer, about one line or the whole cart.
 */
final readonly class CartRefusal
{
    private function __construct(
        public string $message,
        public ?int $lineId,
    ) {
        if (trim($message) === '') {
            throw new \InvalidArgumentException('A refusal tells the customer why.');
        }
    }

    public static function line(int $lineId, string $message): self
    {
        return new self($message, $lineId);
    }

    public static function cart(string $message): self
    {
        return new self($message, null);
    }
}
