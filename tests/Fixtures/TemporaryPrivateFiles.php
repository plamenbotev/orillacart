<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures;

use OrillaCart\Shared\Files\PrivateFiles;
use OrillaCart\Shared\Files\StorageCheck;
use OrillaCart\Shared\Files\StoredFile;

/**
 * Keeps private files in a temporary folder.
 */
final class TemporaryPrivateFiles implements PrivateFiles
{
    public readonly string $directory;
    /** @var list<string> */
    public array $deleted = [];

    public function __construct()
    {
        $this->directory = sys_get_temp_dir() . '/orillacart-files-' . bin2hex(random_bytes(4));
        mkdir($this->directory);
    }

    public function __destruct()
    {
        $files = glob($this->directory . '/*');
        foreach ($files === false ? [] : $files as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    public function store(string $uploadedPath, string $filename): StoredFile
    {
        $key = bin2hex(random_bytes(16));
        rename($uploadedPath, $this->directory . '/' . $key);

        return new StoredFile($key, $filename, 'application/pdf', (int) filesize($this->directory . '/' . $key));
    }

    public function path(string $key): ?string
    {
        return is_file($this->directory . '/' . $key) ? $this->directory . '/' . $key : null;
    }

    public function delete(string $key): void
    {
        $this->deleted[] = $key;
        @unlink($this->directory . '/' . $key);
    }

    public function check(bool $fresh = false): StorageCheck
    {
        return new StorageCheck(StorageCheck::OUTSIDE_WEB_ROOT, $this->directory, null, '');
    }

    /** A file at a temporary path, as an upload arrives. */
    public static function upload(string $content): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'upload');
        file_put_contents($path, $content);

        return $path;
    }
}
