<?php

declare(strict_types=1);

namespace OrillaCart\Forms\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use OrillaCart\Forms\Domain\FormUpload;
use OrillaCart\Forms\Domain\FormUploadRepository;

final readonly class DoctrineFormUploadRepository implements FormUploadRepository
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function find(int $id): ?FormUpload
    {
        return $this->entityManager->find(FormUpload::class, $id);
    }

    public function withToken(string $token): ?FormUpload
    {
        return $this->entityManager->getRepository(FormUpload::class)->findOneBy(['tokenHash' => FormUpload::hash($token)]);
    }

    public function ofOrder(int $orderId): array
    {
        /** @var list<FormUpload> */
        return $this->entityManager->getRepository(FormUpload::class)->findBy(['orderId' => $orderId], ['id' => 'ASC']);
    }

    public function untakenBefore(\DateTimeImmutable $sent, \DateTimeImmutable $held, int $limit): array
    {
        /** @var list<FormUpload> */
        return $this->entityManager->createQueryBuilder()
            ->select('u')
            ->from(FormUpload::class, 'u')
            ->where('u.orderId IS NULL')
            ->andWhere('(u.heldAt IS NULL AND u.createdAt < :sent) OR u.heldAt < :held')
            ->setParameter('sent', $sent, 'datetime_immutable')
            ->setParameter('held', $held, 'datetime_immutable')
            ->orderBy('u.id')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function untakenBytes(): int
    {
        $bytes = $this->entityManager->createQueryBuilder()
            ->select('COALESCE(SUM(u.size), 0)')
            ->from(FormUpload::class, 'u')
            ->where('u.orderId IS NULL')
            ->getQuery()
            ->getSingleScalarResult();

        return is_numeric($bytes) ? (int) $bytes : 0;
    }

    public function save(FormUpload $upload): void
    {
        $this->entityManager->persist($upload);
        $this->entityManager->flush();
    }

    public function remove(FormUpload $upload): void
    {
        $this->entityManager->remove($upload);
        $this->entityManager->flush();
    }
}
