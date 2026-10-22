<?php

declare(strict_types=1);

namespace OrillaCart\Forms\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use OrillaCart\Forms\Domain\Form;
use OrillaCart\Forms\Domain\FormPlacement;
use OrillaCart\Forms\Domain\FormRepository;

final readonly class DoctrineFormRepository implements FormRepository
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function all(): array
    {
        /** @var list<Form> */
        return $this->entityManager->createQueryBuilder()
            ->select('f')
            ->from(Form::class, 'f')
            ->orderBy('f.position')
            ->addOrderBy('f.id')
            ->getQuery()
            ->getResult();
    }

    public function active(FormPlacement $placement): array
    {
        /** @var list<Form> */
        return $this->entityManager->createQueryBuilder()
            ->select('f')
            ->from(Form::class, 'f')
            ->where('f.placement = :placement')
            ->andWhere('f.active = true')
            ->setParameter('placement', $placement->value)
            ->orderBy('f.position')
            ->addOrderBy('f.id')
            ->getQuery()
            ->getResult();
    }

    public function find(int $id): ?Form
    {
        return $this->entityManager->find(Form::class, $id);
    }

    public function save(Form $form): void
    {
        $this->entityManager->persist($form);
        $this->entityManager->flush();
    }

    public function remove(Form $form): void
    {
        $this->entityManager->remove($form);
        $this->entityManager->flush();
    }
}
