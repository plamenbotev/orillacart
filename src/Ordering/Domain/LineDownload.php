<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Domain;

/**
 * A file that came with an order line, kept as it was sold: the product may drop or rename it later.
 */
final readonly class LineDownload
{
    public function __construct(
        public int $fileId,
        public string $name,
        public string $storageKey,
        public string $mimeType,
    ) {}
}
