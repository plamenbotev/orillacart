<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress;

use OrillaCart\Shared\Lifecycle\LifecycleListener;
use OrillaCart\Shared\Lifecycle\RemovesShopData;
use OrillaCart\Shared\WordPress\AutoloadedOption;
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
        // '0' rather than no option: a missing one is a query on every request without an object cache.
        update_option(self::OPTION, $opensSoon ? '1' : '0', true);
    }

    public function activate(): void
    {
        $this->upgrade(null);
    }

    /** Read on every storefront request: stored with its defaults, autoloaded, so reading it costs no query. */
    public function upgrade(?string $fromVersion): void
    {
        AutoloadedOption::keep(self::OPTION, fn() => $this->save($this->opensSoon()));
    }

    public function deactivate(): void {}

    public function uninstall(): void
    {
        delete_option(self::OPTION);
    }
}
