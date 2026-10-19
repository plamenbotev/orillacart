<?php

declare(strict_types=1);

namespace OrillaCart\Platform\Twig;

use OrillaCart\Platform\PluginContext;
use OrillaCart\Shared\Lifecycle\LifecycleListener;

/**
 * The compiled templates in wp-content/cache/orillacart: those of older versions go after an update, and the
 * whole folder when the plugin is deleted. Nothing of the shop's is kept there, so it goes whatever the shop chose.
 */
final readonly class TemplateCache implements LifecycleListener
{
    public function __construct(private PluginContext $context) {}

    public function activate(): void {}

    public function upgrade(?string $fromVersion): void
    {
        $versions = self::folder() . '/twig';
        $found = is_dir($versions) ? scandir($versions) : false;
        foreach ($found === false ? [] : $found as $version) {
            if ($version !== '.' && $version !== '..' && $version !== TwigFactory::versionFolder($this->context->version)) {
                self::remove($versions . '/' . $version);
            }
        }
    }

    public function deactivate(): void {}

    public function uninstall(): void
    {
        self::remove(self::folder());
    }

    private static function folder(): string
    {
        return (\defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : '') . '/cache/orillacart';
    }

    private static function remove(string $path): void
    {
        if (!is_dir($path) || is_link($path)) {
            return;
        }
        $entries = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($entries as $entry) {
            if (!$entry instanceof \SplFileInfo) {
                continue;
            }
            $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : wp_delete_file($entry->getPathname());
        }
        rmdir($path);
    }
}
