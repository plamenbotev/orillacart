<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress;

use OrillaCart\Customers\Application\WishlistSetting;
use OrillaCart\Storefront\Application\StorefrontSettingsService;
use OrillaCart\Storefront\Domain\WishlistPolicy;

/**
 * Wishlists are kept unless the storefront's setting turned them off.
 */
final readonly class StorefrontWishlistSetting implements WishlistSetting
{
    public function __construct(private StorefrontSettingsService $settings) {}

    public function keepsWishlists(): bool
    {
        return $this->settings->current()->wishlist !== WishlistPolicy::Off;
    }
}
