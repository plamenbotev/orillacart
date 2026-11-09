<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Application\Dto;

/** What a zip of an extension holds (design 58): its files, its extension.json when it has one, and what was left out. */
final readonly class PackageArchive
{
    /**
     * @param array<string, string>     $files    by path in the package
     * @param array<string, mixed>|null $manifest extension.json: id, name, version, translations
     * @param list<string>              $skipped  the files that are not an extension's, by their path in the zip
     */
    public function __construct(
        public array $files,
        public ?array $manifest = null,
        public array $skipped = [],
    ) {}
}
