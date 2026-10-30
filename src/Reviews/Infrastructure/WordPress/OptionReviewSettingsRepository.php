<?php

declare(strict_types=1);

namespace OrillaCart\Reviews\Infrastructure\WordPress;

use OrillaCart\Reviews\Domain\ReviewPolicy;
use OrillaCart\Reviews\Domain\ReviewSettings;
use OrillaCart\Reviews\Domain\ReviewSettingsRepository;
use OrillaCart\Shared\Lifecycle\LifecycleListener;
use OrillaCart\Shared\Lifecycle\RemovesShopData;
use OrillaCart\Shared\WordPress\AutoloadedOption;

final readonly class OptionReviewSettingsRepository implements ReviewSettingsRepository, LifecycleListener, RemovesShopData
{
    public const string OPTION = 'orillacart_reviews';

    public function load(): ReviewSettings
    {
        $stored = get_option(self::OPTION);
        $policy = \is_array($stored) && \is_string($stored['policy'] ?? null) ? ReviewPolicy::tryFrom($stored['policy']) : null;

        return new ReviewSettings($policy ?? ReviewPolicy::Anyone);
    }

    public function save(ReviewSettings $settings): void
    {
        update_option(self::OPTION, ['policy' => $settings->policy->value], true);
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
