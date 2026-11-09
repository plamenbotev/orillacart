<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Infrastructure\WordPress;

use OrillaCart\Packages\Application\AssetFiles;

/**
 * An extension's assets under the site's uploads folder (`uploads/orillacart/extensions/<extension>/`), which
 * the web server serves as any upload; on a network each site has its own uploads. A file is written as
 * `<name>-<hash>.<ext>`, so its URL changes with its content and may be cached for long; writing a new one
 * removes the older of the same name.
 */
final readonly class UploadedAssetFiles implements AssetFiles
{
    private const string FOLDER = 'orillacart/extensions';

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
            $older = glob($folder . '/' . basename($base) . '-*' . $suffix);
            foreach ($older === false ? [] : $older as $old) {
                @unlink($old);
            }
            file_put_contents($path, $content, \LOCK_EX);
        }

        return $uploads['baseurl'] . '/' . $relative;
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
