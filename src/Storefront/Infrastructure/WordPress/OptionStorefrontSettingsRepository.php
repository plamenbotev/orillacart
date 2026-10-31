<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress;

use OrillaCart\Shared\Lifecycle\LifecycleListener;
use OrillaCart\Shared\Lifecycle\RemovesShopData;
use OrillaCart\Shared\WordPress\AutoloadedOption;
use OrillaCart\Storefront\Domain\SharingTags;
use OrillaCart\Storefront\Domain\StorefrontRuleViolation;
use OrillaCart\Storefront\Domain\StorefrontSettings;
use OrillaCart\Storefront\Domain\StorefrontSettingsRepository;
use OrillaCart\Storefront\Domain\StoreNotice;
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
            ($stored['hide_single_value_filters'] ?? true) !== false,
            ($stored['list_untranslated'] ?? true) !== false,
            self::notice(\is_array($stored['notice'] ?? null) ? $stored['notice'] : []),
        );
    }

    /**
     * @param array<mixed> $stored
     */
    private static function notice(array $stored): StoreNotice
    {
        $text = static fn(string $key): string => \is_string($stored[$key] ?? null) ? $stored[$key] : '';
        try {
            return new StoreNotice(($stored['shown'] ?? false) === true, $text('text'), $text('link'), $text('link_label'), ($stored['dismissible'] ?? true) !== false);
        } catch (StorefrontRuleViolation) {
            // Changed by hand into one the shop would refuse: none, until it is saved again.
            return new StoreNotice();
        }
    }

    public function save(StorefrontSettings $settings): void
    {
        update_option(self::OPTION, [
            'structured_data' => $settings->structuredData,
            'sharing_tags' => $settings->sharingTags->value,
            'wishlist' => $settings->wishlist->value,
            'recently_viewed' => $settings->recentlyViewed,
            'data_layer' => $settings->dataLayer,
            'hide_single_value_filters' => $settings->hideSingleValueFilters,
            'list_untranslated' => $settings->listUntranslated,
            'notice' => [
                'shown' => $settings->notice->shown,
                'text' => $settings->notice->text,
                'link' => $settings->notice->link,
                'link_label' => $settings->notice->linkLabel,
                'dismissible' => $settings->notice->dismissible,
            ],
        ], true);
    }

    public function activate(): void
    {
        $this->upgrade(null);
    }

    /** Read on every storefront request: stored with its defaults, autoloaded, so reading it costs no query. */
    public function upgrade(?string $fromVersion): void
    {
        AutoloadedOption::keep(self::OPTION, fn() => $this->save($this->load()));
    }

    public function deactivate(): void {}

    public function uninstall(): void
    {
        delete_option(self::OPTION);
    }
}
