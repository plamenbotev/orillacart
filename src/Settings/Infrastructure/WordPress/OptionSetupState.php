<?php

declare(strict_types=1);

namespace OrillaCart\Settings\Infrastructure\WordPress;

use OrillaCart\Settings\Application\SetupState;
use OrillaCart\Shared\Lifecycle\LifecycleListener;
use OrillaCart\Shared\Lifecycle\RemovesShopData;

final readonly class OptionSetupState implements SetupState, LifecycleListener, RemovesShopData
{
    public const string OPTION = 'orillacart_setup_finished';

    public function finished(): bool
    {
        return get_option(self::OPTION) !== false;
    }

    public function finish(): void
    {
        update_option(self::OPTION, gmdate(DATE_ATOM), false);
    }

    public function activate(): void {}

    public function upgrade(?string $fromVersion): void {}

    public function deactivate(): void {}

    public function uninstall(): void
    {
        delete_option(self::OPTION);
    }
}
