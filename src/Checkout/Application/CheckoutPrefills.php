<?php

declare(strict_types=1);

namespace OrillaCart\Checkout\Application;

/**
 * What a guest's checkout starts with besides their account's: the buyer and address a shopper's agent gave for
 * the cart it handed to them (design 75), so they review and pay rather than type it all again.
 */
interface CheckoutPrefills
{
    /**
     * @return array{email: string, billing: array<string, string>}|null the address as the checkout form names its fields
     */
    public function forCart(#[\SensitiveParameter] string $cartToken): ?array;
}
