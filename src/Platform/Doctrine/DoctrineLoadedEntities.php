<?php

declare(strict_types=1);

namespace OrillaCart\Platform\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityNotFoundException;
use Doctrine\ORM\UnitOfWork;
use OrillaCart\Shared\Persistence\LoadedEntities;

final readonly class DoctrineLoadedEntities implements LoadedEntities
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function forget(): void
    {
        $this->entityManager->clear();
    }

    public function reread(string ...$classes): void
    {
        $unitOfWork = $this->entityManager->getUnitOfWork();
        foreach ($classes as $class) {
            $root = $this->entityManager->getClassMetadata($class)->rootEntityName;
            foreach ($unitOfWork->getIdentityMap()[$root] ?? [] as $entity) {
                if (!$entity instanceof $class || $unitOfWork->getEntityState($entity) !== UnitOfWork::STATE_MANAGED) {
                    continue;
                }
                try {
                    $this->entityManager->refresh($entity);
                } catch (EntityNotFoundException) {
                    // Its row is gone: saving it would write nothing, or bring it back.
                    $this->entityManager->detach($entity);
                }
            }
        }
    }
}
