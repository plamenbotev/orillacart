<?php

declare(strict_types=1);

namespace OrillaCart\Cart\Application\Dto;

/**
 * A cart left at checkout whose customer asked to be reminded of it: to whom, in which language, what it holds now,
 * and, for a visitor's cart, the token of the link that brings it back.
 */
final readonly class CartReminder
{
    public function __construct(
        public int $abandonedId,
        public string $email,
        public ?string $locale,
        public ?int $customerId,
        public CartView $cart,
        /** Null for a customer's cart: they log in to it. */
        public ?string $recoverToken,
    ) {}
}
