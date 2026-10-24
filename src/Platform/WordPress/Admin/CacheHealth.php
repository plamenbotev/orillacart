<?php

declare(strict_types=1);

namespace OrillaCart\Platform\WordPress\Admin;

use OrillaCart\Platform\CacheFolders;
use OrillaCart\Platform\PluginContext;
use OrillaCart\Shared\WordPress\HealthCheck;
use OrillaCart\Shared\WordPress\HealthStatus;

/**
 * Whether the shop can keep its compiled templates and database mapping: without a writable
 * wp-content/cache/orillacart it works them out again on every request.
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

        return $fields;
    }
}
