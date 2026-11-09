<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Application\Dto;

/** A zip installed: the extension as it is now, and the zip's files that are not an extension's. */
final readonly class ArchiveInstalled
{
    /**
     * @param list<string> $skipped by their path in the zip
     */
    public function __construct(
        public PackageView $extension,
        public array $skipped,
    ) {}
}
