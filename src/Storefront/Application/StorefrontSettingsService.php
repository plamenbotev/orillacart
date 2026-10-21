<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Application;

use OrillaCart\Storefront\Application\Dto\StorefrontSettingsView;
use OrillaCart\Storefront\Application\Dto\UpdateStorefrontSettings;
use OrillaCart\Storefront\Domain\SharingTags;
use OrillaCart\Storefront\Domain\StorefrontSettings;
use OrillaCart\Storefront\Domain\StorefrontSettingsRepository;
use OrillaCart\Storefront\Domain\WishlistPolicy;

final readonly class StorefrontSettingsService
{
    public function __construct(private StorefrontSettingsRepository $settings) {}

    public function current(): StorefrontSettings
    {
        return $this->settings->load();
    }

    public function settings(): StorefrontSettingsView
    {
        return StorefrontSettingsView::of($this->settings->load());
    }

    public function update(UpdateStorefrontSettings $command): StorefrontSettingsView
    {
        $settings = new StorefrontSettings($command->structuredData, SharingTags::from($command->sharingTags), WishlistPolicy::from($command->wishlist), $command->recentlyViewed, $command->dataLayer);
        $this->settings->save($settings);

        return StorefrontSettingsView::of($settings);
    }
}
