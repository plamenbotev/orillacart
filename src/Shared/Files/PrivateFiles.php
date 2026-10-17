<?php

declare(strict_types=1);

namespace OrillaCart\Shared\Files;

/**
 * Files only the plugin hands out, such as what customers bought to download. They are never media library items,
 * whose addresses WordPress publishes, and the web server must not serve them directly.
 */
interface PrivateFiles
{
    /**
     * Moves an uploaded file into the store under a random name.
     *
     * @throws \RuntimeException when the file cannot be stored
     */
    public function store(string $uploadedPath, string $filename): StoredFile;

    /** The file's path on disk; null when the store does not have it. */
    public function path(string $key): ?string;

    public function delete(string $key): void;

    public function check(bool $fresh = false): StorageCheck;
}
