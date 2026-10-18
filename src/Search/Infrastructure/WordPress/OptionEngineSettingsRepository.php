<?php

declare(strict_types=1);

namespace OrillaCart\Search\Infrastructure\WordPress;

use OrillaCart\Search\Domain\EngineSettings;
use OrillaCart\Search\Domain\EngineSettingsRepository;
use OrillaCart\Search\Domain\SearchEngine;
use OrillaCart\Shared\Lifecycle\LifecycleListener;

/**
 * The engine settings in one WordPress option, read when the shop searches or indexes.
 */
final readonly class OptionEngineSettingsRepository implements EngineSettingsRepository, LifecycleListener
{
    public const string OPTION = 'orillacart_search_engine';

    public function load(): EngineSettings
    {
        $stored = get_option(self::OPTION);
        if (!\is_array($stored)) {
            return new EngineSettings();
        }
        $text = static fn(string $key, string $default = ''): string => \is_string($stored[$key] ?? null) ? $stored[$key] : $default;

        return new EngineSettings(
            SearchEngine::tryFrom($text('engine')) ?? SearchEngine::Database,
            $text('host'),
            $text('username'),
            $text('password'),
            $text('index', 'orillacart'),
            ($stored['ready'] ?? false) === true,
        );
    }

    public function save(EngineSettings $settings): void
    {
        update_option(self::OPTION, [
            'engine' => $settings->engine->value,
            'host' => $settings->host,
            'username' => $settings->username,
            'password' => $settings->password,
            'index' => $settings->index,
            'ready' => $settings->ready,
        ], false);
    }

    public function activate(): void {}

    public function upgrade(?string $fromVersion): void {}

    public function deactivate(): void {}

    public function uninstall(): void
    {
        delete_option(self::OPTION);
    }
}
