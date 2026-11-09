<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Application\Dto;

/** An extension as a zip to download. The REST API speaks JSON, so the zip is base64. */
final readonly class ArchiveFile
{
    public function __construct(
        public string $filename,
        public string $content,
        public string $mimeType = 'application/zip',
    ) {}
}
