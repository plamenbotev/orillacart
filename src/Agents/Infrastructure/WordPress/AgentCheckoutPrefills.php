<?php

declare(strict_types=1);

namespace OrillaCart\Agents\Infrastructure\WordPress;

use OrillaCart\Agents\Application\AgentCheckouts;
use OrillaCart\Cart\Application\CartService;
use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Checkout\Application\CheckoutPrefills;

/** A handed-over cart's checkout starts with the buyer and address its agent gave, the cart claimed or not. */
final readonly class AgentCheckoutPrefills implements CheckoutPrefills
{
    public function __construct(private AgentCheckouts $checkouts, private CartService $carts) {}

    public function forCart(CartOwner $owner): ?array
    {
        $cartId = $this->carts->contents($owner)?->cartId;

        return $cartId === null ? null : $this->checkouts->given($cartId);
    }
}
