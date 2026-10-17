<?php

declare(strict_types=1);

namespace OrillaCart\Shared\Files;

/**
 * A file the shop keeps privately: its key in the store, and what it was when uploaded.
 */
final readonly class StoredFile
{
    public function __construct(
        public string $key,
        public string $filename,
        public string $mimeType,
        public int $size,
    ) {}
}
