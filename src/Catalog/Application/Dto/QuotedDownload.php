<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Application\Dto;

/**
 * A file the buyer of a quoted item may download once paid: what the order keeps of it.
 */
final readonly class QuotedDownload
{
    public function __construct(
        public int $fileId,
        public string $name,
        public string $storageKey,
        public string $mimeType,
    ) {}
}
