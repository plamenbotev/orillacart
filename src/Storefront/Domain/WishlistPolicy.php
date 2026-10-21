<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Domain;

/**
 * Who may keep a wishlist of the shop's products.
 */
enum WishlistPolicy: string
{
    /** Visitors too: theirs is kept in their browser, and joins their account when they log in. */
    case Guests = 'guests';
    /** Customers with an account; visitors are asked to log in. */
    case Accounts = 'accounts';
    case Off = 'off';
}
