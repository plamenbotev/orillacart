<?php

declare(strict_types=1);

namespace OrillaCart\Search\Infrastructure\WordPress;

use OrillaCart\Search\Domain\EngineSettings;
use OrillaCart\Search\Domain\EngineSettingsRepository;
use OrillaCart\Search\Domain\SearchEngine;
use OrillaCart\Search\Infrastructure\EngineCatalogSearch;
use OrillaCart\Shared\Lifecycle\LifecycleListener;
use OrillaCart\Shared\Lifecycle\RemovesShopData;
use OrillaCart\Shared\Security\HoldsSealedValues;
use OrillaCart\Shared\Security\Secrets;
use OrillaCart\Shared\WordPress\AutoloadedOption;

/**
 * The engine settings in one WordPress option, read when the shop searches or indexes; the password sealed, so the
 * option is autoloaded like the other settings every listing reads.
 */
final readonly class OptionEngineSettingsRepository implements EngineSettingsRepository, LifecycleListener, RemovesShopData, HoldsSealedValues
{
    public const string OPTION = 'orillacart_search_engine';

    public function __construct(private Secrets $secrets) {}

    /** An index of the site's own by default: on a network every shop would otherwise write into one "orillacart". */
    public static function defaultIndex(): string
    {
        return is_multisite() ? 'orillacart-site-' . get_current_blog_id() : 'orillacart';
    }

    public function load(): EngineSettings
    {
        $stored = get_option(self::OPTION);
        if (!\is_array($stored)) {
            return new EngineSettings(index: self::defaultIndex());
        }
        $text = static fn(string $key, string $default = ''): string => \is_string($stored[$key] ?? null) ? $stored[$key] : $default;

        return new EngineSettings(
            SearchEngine::tryFrom($text('engine')) ?? SearchEngine::Database,
            $text('host'),
            $text('username'),
            $this->secrets->open($text('password')) ?? '',
            $text('index', self::defaultIndex()),
            ($stored['ready'] ?? false) === true,
        );
    }

    public function save(EngineSettings $settings): void
    {
        update_option(self::OPTION, [
            'engine' => $settings->engine->value,
            'host' => $settings->host,
            'username' => $settings->username,
            'password' => $this->secrets->seal($settings->password),
            'index' => $settings->index,
            'ready' => $settings->ready,
        ], true);
        // Settings saved anew (another server, an index built): an outage remembered of the old ones is forgotten.
        update_option(EngineCatalogSearch::OUTAGE_OPTION, '0', true);
    }

    public function sealedValues(): array
    {
        $stored = get_option(self::OPTION);
        $password = \is_array($stored) ? $stored['password'] ?? null : null;

        return \is_string($password) && $password !== '' ? [$password] : [];
    }

    public function resealValues(): void
    {
        $this->save($this->load());
    }

    public function activate(): void
    {
        $this->upgrade(null);
    }

    /** Read on every storefront request: stored with its defaults, autoloaded, so reading it costs no query. */
    public function upgrade(?string $fromVersion): void
    {
        AutoloadedOption::keep(self::OPTION, fn() => $this->save($this->load()));
        AutoloadedOption::keep(EngineCatalogSearch::OUTAGE_OPTION, static function (): void {
            add_option(EngineCatalogSearch::OUTAGE_OPTION, '0', '', true);
        });
    }

    public function deactivate(): void {}

    public function uninstall(): void
    {
        delete_option(self::OPTION);
        delete_option(EngineCatalogSearch::OUTAGE_OPTION);
    }
}
