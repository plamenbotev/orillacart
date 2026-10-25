<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress;

use OrillaCart\Shared\Lifecycle\LifecycleListener;
use OrillaCart\Shared\Lifecycle\RemovesShopData;
use OrillaCart\Storefront\Domain\OpeningRepository;

final readonly class OptionOpeningRepository implements OpeningRepository, LifecycleListener, RemovesShopData
{
    public const string OPTION = 'orillacart_opens_soon';

    public function opensSoon(): bool
    {
        return get_option(self::OPTION) === '1';
    }

    public function save(bool $opensSoon): void
    {
        $opensSoon ? update_option(self::OPTION, '1', true) : delete_option(self::OPTION);
    }

    public function activate(): void {}

    public function upgrade(?string $fromVersion): void {}

    public function deactivate(): void {}

    public function uninstall(): void
    {
        delete_option(self::OPTION);
    }
}
