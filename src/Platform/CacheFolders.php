<?php

declare(strict_types=1);

namespace OrillaCart\Platform;

use OrillaCart\Shared\Lifecycle\LifecycleListener;

/**
 * What the plugin compiles once and reads on every request, in wp-content/cache/orillacart/<kind>/<version>: the
 * Twig templates and Doctrine's mapping. Older versions' folders go after an update, and the whole folder when
 * the plugin is deleted. Nothing of the shop's is kept there, so it goes whatever the shop chose.
 */
final readonly class CacheFolders implements LifecycleListener
{
    public const array KINDS = ['twig', 'twig-emails', 'doctrine', 'pdf-fonts'];

    public function __construct(private PluginContext $context) {}

    /**
     * The folder for one kind and version, made when missing; null without a writable one (the caller then keeps
     * what it compiles in memory).
     */
    public static function path(string $kind, string $version): ?string
    {
        if (!\defined('WP_CONTENT_DIR')) {
            return null;
        }
        $dir = self::root() . '/' . $kind . '/' . self::versionFolder($version);
        if (!is_dir($dir) && !wp_mkdir_p($dir)) {
            return null;
        }

        return is_writable($dir) ? $dir : null;
    }

    /** Forgets what this version compiled: the next request compiles it again. */
    public static function clear(string $version): void
    {
        foreach (self::KINDS as $kind) {
            self::remove(self::root() . '/' . $kind . '/' . self::versionFolder($version));
        }
    }

    /** The folder's name for a version of the plugin. */
    private static function versionFolder(string $version): string
    {
        return preg_replace('/[^A-Za-z0-9._-]/', '_', $version) ?? 'unknown';
    }

    public function activate(): void {}

    public function upgrade(?string $fromVersion): void
    {
        foreach (self::KINDS as $kind) {
            $versions = self::root() . '/' . $kind;
            $found = is_dir($versions) ? scandir($versions) : false;
            foreach ($found === false ? [] : $found as $version) {
                if ($version !== '.' && $version !== '..' && $version !== self::versionFolder($this->context->version)) {
                    self::remove($versions . '/' . $version);
                }
            }
        }
    }

    public function deactivate(): void {}

    public function uninstall(): void
    {
        self::remove(self::root());
    }

    private static function root(): string
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
