<?php

declare(strict_types=1);

namespace OrillaCart\Newsletter\Infrastructure\WordPress;

use OrillaCart\Newsletter\Domain\NewsletterRuleViolation;
use OrillaCart\Newsletter\Domain\NewsletterSettings;
use OrillaCart\Newsletter\Domain\NewsletterSettingsRepository;
use OrillaCart\Shared\Lifecycle\LifecycleListener;
use OrillaCart\Shared\Lifecycle\RemovesShopData;

final readonly class OptionNewsletterSettingsRepository implements NewsletterSettingsRepository, LifecycleListener, RemovesShopData
{
    public const string OPTION = 'orillacart_newsletter';

    public function load(): NewsletterSettings
    {
        $stored = get_option(self::OPTION);
        if (!\is_array($stored)) {
            return new NewsletterSettings();
        }
        try {
            return new NewsletterSettings(
                ($stored['offered'] ?? false) === true,
                \is_string($stored['consent'] ?? null) ? $stored['consent'] : NewsletterSettings::DEFAULT_CONSENT,
                \is_array($stored['lists'] ?? null) ? array_values(array_filter($stored['lists'], 'is_string')) : [],
                ($stored['confirm_new'] ?? true) !== false,
            );
        } catch (NewsletterRuleViolation) {
            return new NewsletterSettings();
        }
    }

    public function save(NewsletterSettings $settings): void
    {
        update_option(self::OPTION, ['offered' => $settings->offered, 'consent' => $settings->consent, 'lists' => $settings->lists, 'confirm_new' => $settings->confirmNew], true);
    }

    public function activate(): void {}

    public function upgrade(?string $fromVersion): void {}

    public function deactivate(): void {}

    public function uninstall(): void
    {
        delete_option(self::OPTION);
    }
}
