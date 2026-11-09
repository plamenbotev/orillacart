<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Infrastructure;

use OrillaCart\Packages\Application\Dto\PackageArchive;
use OrillaCart\Packages\Application\Dto\PackageExport;
use OrillaCart\Packages\Application\PackageArchives;
use OrillaCart\Packages\Domain\Package;
use OrillaCart\Packages\Domain\PackageFile;
use OrillaCart\Packages\Domain\PackageRuleViolation;

/**
 * Zips of extensions with PHP's zip extension (design 58). Every entry is read into memory, never unpacked to
 * disk, up to its limit and no further whatever its header says; its path goes through the package's own check.
 * Hidden files and folders (`.DS_Store`, `__MACOSX/`) are left out without a word, files a package cannot hold
 * are left out and named.
 */
final readonly class ZipPackageArchives implements PackageArchives
{
    /** The most extension.json may hold: the translations of every word in every language. */
    private const int MAX_MANIFEST = 4 * 1024 * 1024;

    /** The most all the files together may hold, unpacked. */
    private const int MAX_TOTAL = 16 * 1024 * 1024;

    /** A symbolic link, in the Unix mode a zip keeps in an entry's external attributes. */
    private const int LINK = 0o120000;

    public function read(string $path): PackageArchive
    {
        $zip = self::open();
        if (!is_file($path) || filesize($path) > self::MAX_SIZE) {
            throw new PackageRuleViolation('A zip is at most %d MB.', 'orillacart_extension_zip_too_large', values: [intdiv(self::MAX_SIZE, 1024 * 1024)]);
        }
        if ($zip->open($path, \ZipArchive::RDONLY) !== true) {
            throw new PackageRuleViolation('The file is not a zip that opens.', 'orillacart_extension_zip_unreadable');
        }
        try {
            if ($zip->numFiles > self::MAX_ENTRIES) {
                throw new PackageRuleViolation('A zip has at most %d entries.', 'orillacart_extension_zip_too_many', values: [self::MAX_ENTRIES]);
            }
            $entries = [];
            for ($index = 0; $index < $zip->numFiles; ++$index) {
                $stat = $zip->statIndex($index);
                if ($stat === false) {
                    throw new PackageRuleViolation('The zip is damaged.', 'orillacart_extension_zip_unreadable');
                }
                $name = (string) $stat['name'];
                if (str_ends_with($name, '/') || self::hidden($name)) {
                    continue;
                }
                $entries[$index] = $name;
            }
            $folder = self::folder($entries);

            $files = [];
            $skipped = [];
            $manifest = null;
            $total = 0;
            foreach ($entries as $index => $name) {
                $inner = $folder === null ? $name : substr($name, \strlen($folder));
                $isManifest = $inner === self::MANIFEST;
                if (!$isManifest && preg_match('/\.(ocs|html\.twig|md)$/i', $inner) !== 1) {
                    $skipped[] = $name;
                    continue;
                }
                if (!$isManifest) {
                    Package::checkPath($inner);
                }
                $stat = $zip->statIndex($index, \ZipArchive::FL_UNCHANGED);
                if ($stat !== false && ($stat['encryption_method'] ?? 0) !== 0) {
                    throw new PackageRuleViolation('"%s" is encrypted; a zip of an extension is not.', 'orillacart_extension_zip_encrypted', values: [$name]);
                }
                if ($zip->getExternalAttributesIndex($index, $system, $attributes) && $system === \ZipArchive::OPSYS_UNIX && \is_int($attributes) && (($attributes >> 16) & 0o170000) === self::LINK) {
                    throw new PackageRuleViolation('"%s" is a link; a zip of an extension holds the files themselves.', 'orillacart_extension_zip_link', values: [$name]);
                }
                $limit = $isManifest ? self::MAX_MANIFEST : PackageFile::MAX_CONTENT_LENGTH;
                $content = self::content($zip, $index, $limit);
                if ($content === null) {
                    throw $isManifest
                        ? new PackageRuleViolation('%s is at most %d MB.', 'orillacart_extension_zip_too_large', values: [self::MANIFEST, intdiv(self::MAX_MANIFEST, 1024 * 1024)])
                        : new PackageRuleViolation('"%1$s" is larger than a file of an extension may be (%2$d KB).', 'orillacart_extension_file_too_large', values: [$name, intdiv(PackageFile::MAX_CONTENT_LENGTH, 1024)]);
                }
                $total += \strlen($content);
                if ($total > self::MAX_TOTAL) {
                    throw new PackageRuleViolation('The zip unpacks to more than %d MB.', 'orillacart_extension_zip_too_large', values: [intdiv(self::MAX_TOTAL, 1024 * 1024)]);
                }
                if (!mb_check_encoding($content, 'UTF-8')) {
                    throw new PackageRuleViolation('"%s" is not text in UTF-8.', 'orillacart_extension_zip_not_text', values: [$name]);
                }
                if ($isManifest) {
                    $manifest = self::manifest($content);
                    continue;
                }
                if (\count($files) >= Package::MAX_FILES) {
                    throw new PackageRuleViolation('An extension has at most %d files.', 'orillacart_extension_too_many_files', values: [Package::MAX_FILES]);
                }
                $files[$inner] = $content;
            }
        } finally {
            $zip->close();
        }
        ksort($files);

        return new PackageArchive($files, $manifest, $skipped);
    }

    public function write(PackageExport $export): string
    {
        $zip = self::open();
        $path = tempnam(sys_get_temp_dir(), 'orillacart-extension');
        if ($path === false) {
            throw new \RuntimeException('No temporary file for the zip.');
        }
        try {
            if ($zip->open($path, \ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('The zip could not be made.');
            }
            $zip->addFromString(self::MANIFEST, (string) json_encode([
                'format' => $export->format,
                'id' => $export->id,
                'name' => $export->name,
                'version' => $export->version,
                'translations' => $export->translations === [] ? new \stdClass() : $export->translations,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
            foreach ($export->files as $file => $content) {
                $zip->addFromString($file, $content);
            }
            $zip->close();

            return (string) file_get_contents($path);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    private static function open(): \ZipArchive
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new PackageRuleViolation('This server\'s PHP has no zip extension: import the extension\'s JSON file instead, or ask the host to enable zip.', 'orillacart_extension_zip_unsupported');
        }

        return new \ZipArchive();
    }

    /** A file or folder whose name starts with a dot, or macOS's `__MACOSX/`, anywhere in the path. */
    private static function hidden(string $name): bool
    {
        foreach (explode('/', $name) as $segment) {
            // ".." is no hidden file: it reaches the package's check of the path, which refuses it.
            if ($segment === '__MACOSX' || (str_starts_with($segment, '.') && $segment !== '.' && $segment !== '..')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The one folder every entry is in, as a download of a repository has it (`payfast-1.2.0/`); null when the
     * files are at the root.
     *
     * @param array<int, string> $entries
     */
    private static function folder(array $entries): ?string
    {
        $folder = null;
        foreach ($entries as $name) {
            $slash = strpos($name, '/');
            if ($slash === false) {
                return null;
            }
            $first = substr($name, 0, $slash + 1);
            // "../" is no folder around the files: the path keeps it, and the package's check refuses it.
            if ($first === '../' || $first === './' || ($folder !== null && $first !== $folder)) {
                return null;
            }
            $folder = $first;
        }

        return $folder;
    }

    /** The entry's bytes, read up to the limit; null when it holds more. */
    private static function content(\ZipArchive $zip, int $index, int $limit): ?string
    {
        $stream = $zip->getStreamIndex($index);
        if ($stream === false) {
            throw new PackageRuleViolation('The zip is damaged.', 'orillacart_extension_zip_unreadable');
        }
        try {
            $content = '';
            while (!feof($stream) && \strlen($content) <= $limit) {
                $chunk = fread($stream, 65536);
                if ($chunk === false) {
                    throw new PackageRuleViolation('The zip is damaged.', 'orillacart_extension_zip_unreadable');
                }
                $content .= $chunk;
            }
        } finally {
            fclose($stream);
        }

        return \strlen($content) > $limit ? null : $content;
    }

    /** @return array<string, mixed> */
    private static function manifest(string $content): array
    {
        try {
            $manifest = json_decode($content, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new PackageRuleViolation('%s is not JSON.', 'orillacart_extension_zip_manifest', values: [self::MANIFEST]);
        }
        if (!\is_array($manifest) || (array_is_list($manifest) && $manifest !== [])) {
            throw new PackageRuleViolation('%s holds an object: { "id", "name", "version", "translations" }.', 'orillacart_extension_zip_manifest', values: [self::MANIFEST]);
        }
        foreach (['id', 'name', 'version'] as $key) {
            if (isset($manifest[$key]) && !\is_string($manifest[$key])) {
                throw new PackageRuleViolation('The "%1$s" of %2$s is text.', 'orillacart_extension_zip_manifest', values: [$key, self::MANIFEST]);
            }
        }
        if (isset($manifest['translations']) && !\is_array($manifest['translations'])) {
            throw new PackageRuleViolation('The "translations" of %s are an object by word.', 'orillacart_extension_zip_manifest', values: [self::MANIFEST]);
        }

        $named = [];
        foreach ($manifest as $key => $value) {
            if (\is_string($key)) {
                $named[$key] = $value;
            }
        }

        return $named;
    }
}
