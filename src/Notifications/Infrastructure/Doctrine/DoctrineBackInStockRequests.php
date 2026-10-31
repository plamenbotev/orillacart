<?php

declare(strict_types=1);

namespace OrillaCart\Notifications\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use OrillaCart\Notifications\Domain\BackInStockRequest;
use OrillaCart\Notifications\Domain\BackInStockRequests;

final readonly class DoctrineBackInStockRequests implements BackInStockRequests
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function has(string $item, string $email): bool
    {
        return $this->entityManager->getRepository(BackInStockRequest::class)->count(['item' => $item, 'email' => $email]) > 0;
    }

    public function wanted(string $item): bool
    {
        return $this->entityManager->getRepository(BackInStockRequest::class)->count(['item' => $item]) > 0;
    }

    public function forItem(string $item, int $limit): array
    {
        /** @var list<BackInStockRequest> */
        return $this->entityManager->getRepository(BackInStockRequest::class)->findBy(['item' => $item], ['id' => 'ASC'], $limit);
    }

    public function save(BackInStockRequest $request): void
    {
        $this->entityManager->persist($request);
        $this->entityManager->flush();
    }

    public function remove(BackInStockRequest $request): void
    {
        $this->entityManager->remove($request);
        $this->entityManager->flush();
    }

    public function ofEmail(string $email): array
    {
        return $this->entityManager->getRepository(BackInStockRequest::class)->findBy(['email' => $email], ['at' => 'ASC']);
    }

    public function forgetEmail(string $email): int
    {
        $deleted = $this->entityManager->createQuery('DELETE FROM ' . BackInStockRequest::class . ' r WHERE r.email = :email')->setParameter('email', $email)->execute();

        return is_numeric($deleted) ? (int) $deleted : 0;
    }

    public function forgetProducts(array $productIds): void
    {
        if ($productIds !== []) {
            $this->entityManager->createQuery('DELETE FROM ' . BackInStockRequest::class . ' r WHERE r.productId IN (:products)')->setParameter('products', $productIds)->execute();
        }
    }

    public function forgetBefore(\DateTimeImmutable $before): int
    {
        $deleted = $this->entityManager->createQuery('DELETE FROM ' . BackInStockRequest::class . ' r WHERE r.at < :before')->setParameter('before', $before, 'datetime_immutable')->execute();

        return is_numeric($deleted) ? (int) $deleted : 0;
    }
}
