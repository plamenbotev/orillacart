<?php

declare(strict_types=1);

namespace OrillaCart\Audit\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use OrillaCart\Audit\Domain\LoggedProblem;
use OrillaCart\Audit\Domain\LoggedProblemRepository;

final readonly class DoctrineLoggedProblemRepository implements LoggedProblemRepository
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function page(int $offset, int $limit): array
    {
        /** @var list<LoggedProblem> */
        return $this->entityManager->createQueryBuilder()
            ->select('p')->from(LoggedProblem::class, 'p')
            ->orderBy('p.lastAt', 'DESC')->addOrderBy('p.id', 'DESC')
            ->setFirstResult($offset)->setMaxResults($limit)
            ->getQuery()->getResult();
    }

    public function total(): int
    {
        return $this->entityManager->getRepository(LoggedProblem::class)->count([]);
    }

    public function seenSince(\DateTimeImmutable $at): int
    {
        return (int) $this->entityManager->createQuery('SELECT COUNT(p.id) FROM ' . LoggedProblem::class . ' p WHERE p.lastAt >= :at')
            ->setParameter('at', $at)->getSingleScalarResult();
    }

    public function clear(): void
    {
        $this->entityManager->createQuery('DELETE FROM ' . LoggedProblem::class . ' p')->execute();
    }

    public function forgetBefore(\DateTimeImmutable $at): void
    {
        $this->entityManager->createQuery('DELETE FROM ' . LoggedProblem::class . ' p WHERE p.lastAt < :at')
            ->setParameter('at', $at)->execute();
    }
}
