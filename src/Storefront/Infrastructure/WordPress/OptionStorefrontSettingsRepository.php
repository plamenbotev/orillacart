<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress;

use OrillaCart\Shared\Lifecycle\LifecycleListener;
use OrillaCart\Shared\Lifecycle\RemovesShopData;
use OrillaCart\Storefront\Domain\SharingTags;
use OrillaCart\Storefront\Domain\StorefrontSettings;
use OrillaCart\Storefront\Domain\StorefrontSettingsRepository;
use OrillaCart\Storefront\Domain\WishlistPolicy;

final readonly class OptionStorefrontSettingsRepository implements StorefrontSettingsRepository, LifecycleListener, RemovesShopData
{
    public const string OPTION = 'orillacart_storefront';

    public function load(): StorefrontSettings
    {
        $stored = get_option(self::OPTION);
        if (!\is_array($stored)) {
            return new StorefrontSettings();
        }

        return new StorefrontSettings(
            ($stored['structured_data'] ?? true) !== false,
            \is_string($stored['sharing_tags'] ?? null) ? SharingTags::tryFrom($stored['sharing_tags']) ?? SharingTags::Auto : SharingTags::Auto,
            \is_string($stored['wishlist'] ?? null) ? WishlistPolicy::tryFrom($stored['wishlist']) ?? WishlistPolicy::Guests : WishlistPolicy::Guests,
            ($stored['recently_viewed'] ?? true) !== false,
            ($stored['data_layer'] ?? false) === true,
        );
    }

    public function save(StorefrontSettings $settings): void
    {
        update_option(self::OPTION, [
            'structured_data' => $settings->structuredData,
            'sharing_tags' => $settings->sharingTags->value,
            'wishlist' => $settings->wishlist->value,
            'recently_viewed' => $settings->recentlyViewed,
            'data_layer' => $settings->dataLayer,
        ], true);
    }

    public function activate(): void {}

    public function upgrade(?string $fromVersion): void {}

    public function deactivate(): void {}

    public function uninstall(): void
    {
        delete_option(self::OPTION);
    }
}
