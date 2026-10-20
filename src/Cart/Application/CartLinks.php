<?php

declare(strict_types=1);

namespace OrillaCart\Cart\Application;

/**
 * The address a cart reminder links to: the cart page, bringing a visitor's cart back to whichever browser opens it,
 * or the login that leads to a customer's; with a coupon code to put in the cart.
 */
interface CartLinks
{
    public function reminderUrl(?string $recoverToken, ?string $coupon): string;
}
