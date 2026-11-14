<?php

declare(strict_types=1);

namespace OrillaCart\Customers\Application;

/**
 * Whether the shop keeps wishlists at all; the storefront, which shows them, holds the setting.
 */
interface WishlistSetting
{
    public function keepsWishlists(): bool;
}
