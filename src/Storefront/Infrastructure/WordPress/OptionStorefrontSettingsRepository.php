<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress;

use OrillaCart\Shared\Lifecycle\LifecycleListener;
use OrillaCart\Shared\Lifecycle\RemovesShopData;
use OrillaCart\Storefront\Domain\SharingTags;
use OrillaCart\Storefront\Domain\StorefrontSettings;
use OrillaCart\Storefront\Domain\StorefrontSettingsRepository;

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
        );
    }

    public function save(StorefrontSettings $settings): void
    {
        update_option(self::OPTION, ['structured_data' => $settings->structuredData, 'sharing_tags' => $settings->sharingTags->value], true);
    }

    public function activate(): void {}

    public function upgrade(?string $fromVersion): void {}

    public function deactivate(): void {}

    public function uninstall(): void
    {
        delete_option(self::OPTION);
    }
}
