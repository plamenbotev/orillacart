<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Domain;

/** Where an extension's versions are kept: the last PackageVersion::KEPT of each, newest first. */
interface PackageVersions
{
    /** Keeps the package's files as they are now as its next version, and lets the oldest go past the limit. */
    public function keep(Package $package, string $note, \DateTimeImmutable $at): PackageVersion;

    /** @return list<PackageVersion> newest first */
    public function of(Package $package): array;

    public function find(Package $package, int $number): ?PackageVersion;

    /** Lets every version of the package go: on uninstalling, before the package itself. */
    public function forget(Package $package): void;
}
