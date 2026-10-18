<?php

declare(strict_types=1);

namespace OrillaCart\Platform\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use OrillaCart\Shared\Persistence\LoadedEntities;

final readonly class DoctrineLoadedEntities implements LoadedEntities
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function forget(): void
    {
        $this->entityManager->clear();
    }
}
