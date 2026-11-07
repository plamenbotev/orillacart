<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Application\Dto;

use OrillaCart\Packages\Domain\Package;
use OrillaCart\Scripting\Application\CompiledScript;

/** An enabled extension ready to run: its row, its compiled class, and the manifest read from it. */
final readonly class LoadedPackage
{
    /**
     * @param class-string<CompiledScript> $className
     */
    public function __construct(
        public Package $package,
        public string $className,
        public PackageManifest $manifest,
    ) {}
}
