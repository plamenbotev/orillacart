<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Application;

use OrillaCart\Cart\Application\Dto\CartOwner;

/**
 * How far the shopper's cart is from free shipping, as the Free shipping progress block shows it: for the
 * storefront script, which fills the block in on pages a cache may keep, and again as the cart changes.
 */
interface FreeShippingHtml
{
    /** The message and the bar; empty when nothing is to be said. */
    public function render(CartOwner $owner): string;
}
