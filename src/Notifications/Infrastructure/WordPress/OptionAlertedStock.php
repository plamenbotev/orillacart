<?php

declare(strict_types=1);

namespace OrillaCart\Notifications\Infrastructure\WordPress;

use OrillaCart\Notifications\Domain\AlertedStock;
use OrillaCart\Shared\Lifecycle\LifecycleListener;
use OrillaCart\Shared\Lifecycle\RemovesShopData;

/**
 * Keeps the items the last low stock email named in one WordPress option, not autoloaded.
 */
final readonly class OptionAlertedStock implements AlertedStock, LifecycleListener, RemovesShopData
{
    public const string OPTION = 'orillacart_alerted_stock';

    public function keys(): array
    {
        $stored = get_option(self::OPTION);

        return \is_array($stored) ? array_values(array_filter($stored, \is_string(...))) : [];
    }

    public function remember(array $keys): void
    {
        update_option(self::OPTION, $keys, false);
    }

    public function activate(): void {}

    public function upgrade(?string $fromVersion): void {}

    public function deactivate(): void {}

    public function uninstall(): void
    {
        delete_option(self::OPTION);
    }
}
