<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use OrillaCart\Packages\Domain\Package;
use OrillaCart\Packages\Domain\PackageVersion;
use OrillaCart\Packages\Domain\PackageVersions;

final readonly class DoctrinePackageVersions implements PackageVersions
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function keep(Package $package, string $note, \DateTimeImmutable $at): PackageVersion
    {
        $files = [];
        foreach ($package->files() as $file) {
            $files[$file->path()] = $file->content();
        }
        $latest = $this->of($package)[0] ?? null;
        $version = new PackageVersion($package, ($latest?->number() ?? 0) + 1, mb_substr($note, 0, PackageVersion::MAX_NOTE_LENGTH), $files, $package->checksum(), $at);
        $this->entityManager->persist($version);
        $this->entityManager->flush();
        foreach (\array_slice($this->of($package), PackageVersion::KEPT) as $old) {
            $this->entityManager->remove($old);
        }
        $this->entityManager->flush();

        return $version;
    }

    public function of(Package $package): array
    {
        /** @var list<PackageVersion> */
        return $this->entityManager->getRepository(PackageVersion::class)->findBy(['package' => $package], ['number' => 'DESC']);
    }

    public function find(Package $package, int $number): ?PackageVersion
    {
        return $this->entityManager->getRepository(PackageVersion::class)->findOneBy(['package' => $package, 'number' => $number]);
    }

    public function forget(Package $package): void
    {
        // Removed one by one, so none stays managed pointing at a package that is gone.
        foreach ($this->of($package) as $version) {
            $this->entityManager->remove($version);
        }
        $this->entityManager->flush();
    }
}
