<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Application\Dto;

use OrillaCart\Catalog\Domain\DownloadFile;

final readonly class DownloadView
{
    public function __construct(
        public int $id,
        public string $name,
        public string $mimeType,
        public int $size,
    ) {}

    public static function fromFile(DownloadFile $file): self
    {
        return new self($file->id(), $file->name(), $file->mimeType(), $file->size());
    }
}
