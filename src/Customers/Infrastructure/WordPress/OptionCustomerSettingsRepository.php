<?php

declare(strict_types=1);

namespace OrillaCart\Customers\Infrastructure\WordPress;

use OrillaCart\Customers\Domain\AccountMode;
use OrillaCart\Customers\Domain\CustomerSettings;
use OrillaCart\Customers\Domain\CustomerSettingsRepository;
use OrillaCart\Shared\Lifecycle\LifecycleListener;

final readonly class OptionCustomerSettingsRepository implements CustomerSettingsRepository, LifecycleListener
{
    public const string OPTION = 'orillacart_customer_settings';

    public function load(): CustomerSettings
    {
        $stored = get_option(self::OPTION);
        $mode = \is_array($stored) && \is_string($stored['accounts'] ?? null) ? AccountMode::tryFrom($stored['accounts']) : null;

        return new CustomerSettings($mode ?? AccountMode::Optional);
    }

    public function save(CustomerSettings $settings): void
    {
        update_option(self::OPTION, ['accounts' => $settings->accounts->value], true);
    }

    public function activate(): void {}

    public function upgrade(?string $fromVersion): void {}

    public function deactivate(): void {}

    public function uninstall(): void
    {
        delete_option(self::OPTION);
    }
}
