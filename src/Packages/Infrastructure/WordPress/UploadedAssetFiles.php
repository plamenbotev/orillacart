<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Infrastructure\WordPress;

use OrillaCart\Packages\Application\AssetFiles;

/**
 * An extension's assets under the site's uploads folder (`uploads/orillacart/extensions/<extension>/`), which
 * the web server serves as any upload; on a network each site has its own uploads. A file is written as
 * `<name>-<hash>.<ext>`, so its URL changes with its content and may be cached for long; writing a new one
 * removes the older of the same name. The folder has an `.htaccess` that lets Apache serve only such files, as
 * files with no handler, and refuses every other.
 */
final readonly class UploadedAssetFiles implements AssetFiles
{
    private const string FOLDER = 'orillacart/extensions';
    /**
     * Apache's rules for the folder, an allowlist: the files the shop writes (`<name>-<12 hex>.js|css|svg`, the
     * name as Package::ASSET allows it) are served as files, with no handler; every other file is refused, so
     * nothing put there by other means is served or run, whatever handlers the server's setup adds by name.
     */
    public const string HTACCESS = <<<'APACHE'
        # Written by OrillaCart: only the extensions' scripts, stylesheets and SVGs are served, as files; nothing else.
        <IfModule mod_authz_core.c>
            Require all denied
        </IfModule>
        <IfModule !mod_authz_core.c>
            Order deny,allow
            Deny from all
        </IfModule>
        <FilesMatch "^[a-z0-9_-]+(\.(min|[0-9]+))*-[0-9a-f]{12}\.(js|css|svg)$">
            <IfModule mod_authz_core.c>
                Require all granted
            </IfModule>
            <IfModule !mod_authz_core.c>
                Order allow,deny
                Allow from all
            </IfModule>
            SetHandler none
        </FilesMatch>
        <IfModule mod_php.c>
            php_flag engine off
        </IfModule>
        <IfModule mod_php7.c>
            php_flag engine off
        </IfModule>

        APACHE;

    public function publish(string $extension, string $name, string $content): string
    {
        $uploads = wp_upload_dir(null, false);
        $hash = substr(hash('xxh128', $content), 0, 12);
        $dot = strrpos($name, '.');
        $base = $dot === false ? $name : substr($name, 0, $dot);
        $suffix = $dot === false ? '' : substr($name, $dot);
        $relative = self::FOLDER . '/' . $extension . '/' . $base . '-' . $hash . $suffix;
        $path = $uploads['basedir'] . '/' . $relative;
        if (!is_file($path)) {
            $folder = \dirname($path);
            if (!is_dir($folder)) {
                wp_mkdir_p($folder);
            }
            self::guard($uploads['basedir'] . '/' . self::FOLDER);
            $older = glob($folder . '/' . basename($base) . '-*' . $suffix);
            foreach ($older === false ? [] : $older as $old) {
                if (self::isVersionOf(basename($old), basename($base), $suffix)) {
                    @unlink($old);
                }
            }
            file_put_contents($path, $content, \LOCK_EX);
        }

        return $uploads['baseurl'] . '/' . $relative;
    }

    public function folderUrl(string $extension): string
    {
        return wp_upload_dir(null, false)['baseurl'] . '/' . self::FOLDER . '/' . $extension . '/';
    }

    /** Writes the folder's `.htaccess` when it is missing or not the shop's as it is now. */
    public static function guard(string $folder): void
    {
        $file = $folder . '/.htaccess';
        if (is_dir($folder) && (!is_file($file) || file_get_contents($file) !== self::HTACCESS)) {
            file_put_contents($file, self::HTACCESS, \LOCK_EX);
        }
    }

    /**
     * Whether a file is a written version of the named asset: `<base>-<12 hex>.<ext>` exactly, so `a-b-<hash>.js`
     * is not a version of `a.js`.
     */
    public static function isVersionOf(string $file, string $base, string $suffix): bool
    {
        return preg_match('/^' . preg_quote($base, '/') . '-[0-9a-f]{12}' . preg_quote($suffix, '/') . '$/D', $file) === 1;
    }

    public function forget(string $extension): void
    {
        $folder = wp_upload_dir(null, false)['basedir'] . '/' . self::FOLDER . '/' . $extension;
        if (!is_dir($folder)) {
            return;
        }
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($folder, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            if ($file instanceof \SplFileInfo) {
                $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
            }
        }
        @rmdir($folder);
    }
}
