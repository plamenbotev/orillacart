<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Application\Dto;

use OrillaCart\Packages\Domain\PackageFile;

final readonly class PackageFileView
{
    public function __construct(
        public string $path,
        public string $content,
        public string $updatedAt,
    ) {}

    public static function fromFile(PackageFile $file): self
    {
        return new self($file->path(), $file->content(), $file->updatedAt()->format(\DATE_ATOM));
    }
}
