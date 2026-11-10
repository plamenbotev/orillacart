<?php

declare(strict_types=1);

namespace OrillaCart\Platform\WordPress\Admin;

use OrillaCart\Platform\CacheFolders;
use OrillaCart\Platform\PluginContext;
use OrillaCart\Shared\WordPress\HealthCheck;
use OrillaCart\Shared\WordPress\HealthStatus;

/**
 * Whether the shop can keep its compiled templates and database mapping: without a writable
 * wp-content/cache/orillacart it works them out again on every request. What it keeps there is PHP it includes,
 * so a cache folder the server's other users may write to is reported: one every user may write to is not used.
 */
final readonly class CacheHealth implements HealthCheck
{
    public function __construct(private PluginContext $context) {}

    public static function id(): string
    {
        return 'cache';
    }

    public static function label(): string
    {
        return __('The shop’s cache folder', 'orillacart');
    }

    public function check(): HealthStatus
    {
        $loose = CacheFolders::looseFolders();
        $everyone = array_keys(array_filter($loose, static fn(string $who): bool => $who === CacheFolders::WORLD));
        if ($everyone !== []) {
            return HealthStatus::critical(
                __('Every user on the server may write to the shop’s cache', 'orillacart'),
                \sprintf(
                    /* translators: %s: folders */
                    __('The shop runs what it finds in its cache, so it does not use these folders, and compiles its templates and reads its database mapping on every request: %s. Let only the web server’s user write to them (chmod 755).', 'orillacart'),
                    implode(', ', $everyone),
                ),
            );
        }
        if ($loose !== []) {
            return HealthStatus::recommended(
                __('Other users on the server may write to the shop’s cache', 'orillacart'),
                \sprintf(
                    /* translators: %s: folders */
                    __('The shop runs what it finds in its cache, and the group of these folders may change it: %s. Unless only the web server is in that group, let only its user write to them (chmod 755).', 'orillacart'),
                    implode(', ', array_keys($loose)),
                ),
            );
        }
        $missing = array_values(array_filter(CacheFolders::KINDS, fn(string $kind): bool => CacheFolders::path($kind, $this->context->build) === null));
        if ($missing !== []) {
            return HealthStatus::recommended(
                __('The shop cannot keep what it compiles', 'orillacart'),
                \sprintf(
                    /* translators: %s: a folder */
                    __('%s is not writable, so the shop compiles its templates and reads its database mapping on every request, which slows every page. Let the web server write to it.', 'orillacart'),
                    WP_CONTENT_DIR . '/cache/orillacart',
                ),
            );
        }

        return HealthStatus::good(__('The shop keeps its compiled templates', 'orillacart'));
    }

    public function info(): array
    {
        $fields = [];
        foreach (CacheFolders::KINDS as $kind) {
            $path = CacheFolders::path($kind, $this->context->build);
            $fields[$kind] = ['label' => \sprintf('%s cache', ucfirst($kind)), 'value' => $path ?? __('not writable', 'orillacart')];
        }
        $loose = CacheFolders::looseFolders();
        $fields['others_write'] = ['label' => __('Cache folders others may write to', 'orillacart'), 'value' => $loose === [] ? __('none', 'orillacart') : implode(', ', array_map(static fn(string $folder, string $who): string => "{$folder} ({$who})", array_keys($loose), $loose))];

        return $fields;
    }
}
