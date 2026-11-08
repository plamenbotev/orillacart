<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Application\Dto;

use OrillaCart\Packages\Domain\PackageFile;

final readonly class PackageFileView
{
    /**
     * @param string|null $overrides the shop's template this file overrides, when it does
     * @param bool        $stale     whether a release changed that template since this override was saved
     */
    public function __construct(
        public string $path,
        public string $content,
        public string $updatedAt,
        public ?string $overrides = null,
        public bool $stale = false,
    ) {}

    /**
     * @param string|null $currentHash the hash of the shop's template the file overrides, as it is now
     */
    public static function fromFile(PackageFile $file, ?string $currentHash = null): self
    {
        $overrides = $file->overrides();

        return new self($file->path(), $file->content(), $file->updatedAt()->format(\DATE_ATOM), $overrides, $overrides !== null && $currentHash !== null && $currentHash !== $file->originalHash());
    }
}
