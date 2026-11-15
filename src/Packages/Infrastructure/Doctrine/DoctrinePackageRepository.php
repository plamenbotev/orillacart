<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Infrastructure\Doctrine;

use Doctrine\DBAL\Exception\TableNotFoundException;
use Doctrine\ORM\EntityManagerInterface;
use OrillaCart\Packages\Domain\Package;
use OrillaCart\Packages\Domain\PackageRepository;
use OrillaCart\Packages\Domain\PackageState;

final readonly class DoctrinePackageRepository implements PackageRepository
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function all(): array
    {
        /** @var list<Package> */
        return $this->entityManager->getRepository(Package::class)->findBy([], ['name' => 'ASC']);
    }

    public function enabled(): array
    {
        try {
            /** @var list<Package> */
            return $this->entityManager->getRepository(Package::class)->findBy(['state' => PackageState::Enabled], ['slug' => 'ASC']);
        } catch (TableNotFoundException) {
            // Asked on every request, so also on one that comes before the shop's tables are there (files newer
            // than the database, until an administrator's request upgrades it): no extension runs yet.
            return [];
        }
    }

    public function find(string $slug): ?Package
    {
        return $this->entityManager->getRepository(Package::class)->findOneBy(['slug' => $slug]);
    }

    public function save(Package $package): void
    {
        $this->entityManager->persist($package);
        $this->entityManager->flush();
    }

    public function remove(Package $package): void
    {
        $this->entityManager->remove($package);
        $this->entityManager->flush();
    }
}
