<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Application\Dto;

use OrillaCart\Packages\Domain\Package;
use OrillaCart\Packages\Domain\PackageFile;

/**
 * An extension as the admin and the tools see it. The files come with one extension, not with the list, and their
 * contents only where asked: all when it is read, put in place or restored; the one saved after a save; none after
 * a check, a delete, a settings save or a switch (each file then as it was when last read). The settings come as
 * the add-on API shows them: secrets as whether one is set, never the value.
 */
final readonly class PackageView
{
    /**
     * @param list<string>                    $granted
     * @param list<string>                    $needs       what the scripts declare they need, when they compile
     * @param list<PackageFileView>|null      $files
     * @param list<array<string, mixed>>|null $settings    the declared settings with their shown values
     * @param list<string>|null               $findings    what the static check says, when asked
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $version,
        public string $state,
        public bool $stale,
        public array $granted,
        public array $needs,
        public int $failures,
        public ?string $lastProblem,
        public string $createdAt,
        public string $updatedAt,
        public ?array $files = null,
        public ?array $settings = null,
        public ?array $findings = null,
        /**
         * The routes the scripts declare, when they compile (design 59's editor completes their paths).
         *
         * @var list<array{method: string, path: string, who: string}>|null
         */
        public ?array $routes = null,
    ) {}

    /**
     * @param list<string>                    $needs
     * @param bool|list<string>               $withFiles   the files, with every content (true), with the contents
     *                                                     of these paths only, or none (false)
     * @param list<array<string, mixed>>|null $settings
     * @param list<string>|null               $findings
     * @param array<string, string>|null      $currentHashes by path, the hash of the shop's template an override replaces as it is now
     * @param list<array{method: string, path: string, who: string}>|null $routes
     */
    public static function fromPackage(Package $package, array $needs, bool|array $withFiles = false, ?array $settings = null, ?array $findings = null, ?array $currentHashes = null, ?array $routes = null): self
    {
        return new self(
            $package->slug(),
            $package->name(),
            $package->version(),
            $package->state()->value,
            $package->isStale(),
            $package->granted(),
            $needs,
            $package->failures(),
            $package->lastProblem(),
            $package->createdAt()->format(\DATE_ATOM),
            $package->updatedAt()->format(\DATE_ATOM),
            $withFiles === false ? null : array_map(static fn(PackageFile $file): PackageFileView => PackageFileView::fromFile($file, $currentHashes[$file->path()] ?? null, $withFiles === true || \in_array($file->path(), $withFiles, true)), $package->files()),
            $settings,
            $findings,
            $routes,
        );
    }
}
