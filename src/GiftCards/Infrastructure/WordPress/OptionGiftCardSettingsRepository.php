<?php

declare(strict_types=1);

namespace OrillaCart\GiftCards\Infrastructure\WordPress;

use OrillaCart\GiftCards\Domain\GiftCardRuleViolation;
use OrillaCart\GiftCards\Domain\GiftCardSettings;
use OrillaCart\GiftCards\Domain\GiftCardSettingsRepository;
use OrillaCart\Shared\Lifecycle\LifecycleListener;
use OrillaCart\Shared\Lifecycle\RemovesShopData;

final readonly class OptionGiftCardSettingsRepository implements GiftCardSettingsRepository, LifecycleListener, RemovesShopData
{
    public const string OPTION = 'orillacart_gift_cards';

    public function load(): GiftCardSettings
    {
        $stored = get_option(self::OPTION);
        if (!\is_array($stored)) {
            return new GiftCardSettings();
        }
        $months = $stored['valid_months'] ?? null;
        try {
            return new GiftCardSettings(
                array_values(array_filter(\is_array($stored['product_ids'] ?? null) ? $stored['product_ids'] : [], \is_int(...))),
                \is_int($months) ? $months : null,
            );
        } catch (GiftCardRuleViolation) {
            return new GiftCardSettings();
        }
    }

    public function save(GiftCardSettings $settings): void
    {
        update_option(self::OPTION, ['product_ids' => $settings->productIds, 'valid_months' => $settings->validMonths], true);
    }

    public function activate(): void {}

    public function upgrade(?string $fromVersion): void {}

    public function deactivate(): void {}

    public function uninstall(): void
    {
        delete_option(self::OPTION);
    }
}
