<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Domain;

interface PackageRepository
{
    /** @return list<Package> by name */
    public function all(): array;

    /** @return list<Package> the ones that run */
    public function enabled(): array;

    public function find(string $slug): ?Package;

    public function save(Package $package): void;

    public function remove(Package $package): void;
}
