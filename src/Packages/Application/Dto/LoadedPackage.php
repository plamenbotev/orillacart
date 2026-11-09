<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Application\Dto;

use OrillaCart\Packages\Application\ExtensionTranslations;
use OrillaCart\Packages\Domain\Package;
use OrillaCart\Scripting\Application\CompiledScript;

/**
 * An enabled extension ready to run: its row, its compiled class, the manifest read from it, and its words in the
 * language shown (design 57), for what its declarations say to people.
 */
final readonly class LoadedPackage
{
    /**
     * @param class-string<CompiledScript> $className
     */
    public function __construct(
        public Package $package,
        public string $className,
        public PackageManifest $manifest,
        public ?ExtensionTranslations $translations = null,
    ) {}

    /** One of the words its declarations hold, in the language shown; the words as written without translations. */
    public function word(string $text): string
    {
        return $this->translations?->word($this->package->slug(), $text) ?? $text;
    }
}
