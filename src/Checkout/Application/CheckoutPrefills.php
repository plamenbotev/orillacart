<?php

declare(strict_types=1);

namespace OrillaCart\Checkout\Application;

use OrillaCart\Cart\Application\Dto\CartOwner;

/**
 * What a checkout starts with when the buyer has no address of their account's: the buyer and address a shopper's
 * agent gave for the cart it handed to them (design 75), so they review and pay rather than type it all again.
 */
interface CheckoutPrefills
{
    /**
     * @return array{email: string, billing: array<string, string>}|null the address as the checkout form names its fields
     */
    public function forCart(CartOwner $owner): ?array;
}
