<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Application\Dto;

/**
 * Command: add an uploaded file to a product's downloads. The file is at a temporary path until stored.
 */
final readonly class UploadDownload
{
    public function __construct(
        public string $temporaryPath,
        public string $filename,
    ) {}
}
