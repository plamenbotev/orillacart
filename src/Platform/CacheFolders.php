<?php

declare(strict_types=1);

namespace OrillaCart\Platform;

use OrillaCart\Shared\Lifecycle\LifecycleListener;
use OrillaCart\Shared\Lifecycle\OutsideTheSite;

/**
 * What the plugin compiles once and reads on every request, in wp-content/cache/orillacart/<kind>/<version>: the
 * Twig templates and Doctrine's mapping. Older versions' folders go after an update, and the whole folder when
 * the plugin is deleted, with the development container a read-only plugin folder caches in container/<folder>
 * (Kernel), which rebuilds itself when its sources change. Nothing of the shop's is kept there, so it goes whatever the shop chose;
 * it is the network's, so a site deleted from a network leaves it be.
 *
 * What is cached there is PHP the shop includes as found, so the folder is closed to the web (deny rules and an
 * index.php at its root) and to the server's other users: a folder on the way to a cache any user may write to is
 * not used, the shop then compiles in memory, and Site Health says which (CacheHealth). One its group may write to
 * is used, and reported.
 */
final class CacheFolders implements LifecycleListener, OutsideTheSite
{
    public const array KINDS = ['twig', 'twig-emails', 'doctrine', 'pdf-fonts', 'scripts'];
    /** Folders the development container is cached in, one per plugin folder (Kernel). */
    public const string CONTAINER = 'container';
    public const string WORLD = 'world';
    public const string GROUP = 'group';
    /** What the web server is told at the cache's root: hand out nothing (Apache 2.4 and 2.2, IIS). */
    private const array DENY_FILES = [
        '.htaccess' => "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n",
        'web.config' => '<?xml version="1.0"?><configuration><system.webServer><authorization><deny users="*" /></authorization></system.webServer></configuration>' . "\n",
        'index.php' => "<?php\n// Silence.\n",
    ];

    /** @var array<string, true> the roots whose deny files this process wrote or found */
    private static array $protected = [];

    public function __construct(private readonly PluginContext $context) {}

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
        self::protect(self::root());

        return is_writable($dir) && self::trusted($dir) ? $dir : null;
    }

    /**
     * Writes the deny rules and index.php at the cache's root, once a process, again when one went (a deploy or a
     * clean-up may lose them).
     */
    public static function protect(string $root): void
    {
        if (isset(self::$protected[$root]) || !is_dir($root)) {
            return;
        }
        foreach (self::DENY_FILES as $name => $content) {
            $file = $root . '/' . $name;
            if (!is_file($file) || file_get_contents($file) !== $content) {
                if (@file_put_contents($file, $content) === false) {
                    return;
                }
            }
        }
        self::$protected[$root] = true;
    }

    /**
     * Whether the shop may include what is cached in the folder: neither it nor a folder above it, up to the one
     * the cache's root is in, may be written by every user on the server. Above the root a folder with the sticky
     * bit passes, where each user can change only what is their own; in the cache another user could still add
     * a file the shop would take for its own. Folders not made yet are passed over.
     */
    public static function trusted(string $dir, ?string $root = null): bool
    {
        $root ??= self::root();
        foreach (self::foldersUp($dir, $root) as $folder) {
            if (self::looseness($folder, $folder === \dirname($root)) === self::WORLD) {
                return false;
            }
        }

        return true;
    }

    /**
     * The cache's folders that others on the server may write to, with who: WORLD (every user; the shop does not
     * use what is cached under it) or GROUP (the folder's group; it does).
     *
     * @return array<string, string> by path
     */
    public static function looseFolders(?string $root = null): array
    {
        $root ??= self::root();
        $folders = [\dirname($root), $root];
        foreach ([...self::KINDS, self::CONTAINER] as $kind) {
            $folders[] = $root . '/' . $kind;
            $found = is_dir($root . '/' . $kind) ? scandir($root . '/' . $kind) : false;
            foreach ($found === false ? [] : $found as $entry) {
                if ($entry !== '.' && $entry !== '..' && is_dir($root . '/' . $kind . '/' . $entry)) {
                    $folders[] = $root . '/' . $kind . '/' . $entry;
                }
            }
        }
        $loose = [];
        foreach ($folders as $folder) {
            $looseness = self::looseness($folder, $folder === \dirname($root));
            if ($looseness !== null) {
                $loose[$folder] = $looseness;
            }
        }

        return $loose;
    }

    /**
     * WORLD or GROUP when others may write to the folder; null when only its owner may, or it is not there.
     *
     * @param bool $sticky whether the sticky bit makes a folder every user may write to safe enough
     */
    private static function looseness(string $folder, bool $sticky = false): ?string
    {
        clearstatcache(true, $folder);
        $mode = is_dir($folder) ? @fileperms($folder) : false;
        if ($mode === false) {
            return null;
        }

        return match (true) {
            ($mode & 0o002) !== 0 && !($sticky && ($mode & 0o1000) !== 0) => self::WORLD,
            ($mode & 0o020) !== 0 => self::GROUP,
            default => null,
        };
    }

    /**
     * The folder and those above it, up to the one the root is in.
     *
     * @return list<string>
     */
    private static function foldersUp(string $dir, string $root): array
    {
        $stop = \dirname($root);
        $folders = [];
        for ($at = rtrim($dir, '/'); str_starts_with($at . '/', $stop . '/') && $at !== $stop; $at = \dirname($at)) {
            $folders[] = $at;
        }
        $folders[] = $stop;

        return $folders;
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
                if ($version !== '.' && $version !== '..' && $version !== self::versionFolder($this->context->build)) {
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

    public static function root(): string
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
