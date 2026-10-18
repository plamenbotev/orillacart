<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use OrillaCart\Payment\Domain\Refund;
use OrillaCart\Payment\Domain\RefundRepository;

final readonly class DoctrineRefundRepository implements RefundRepository
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function find(int $id): ?Refund
    {
        return $this->entityManager->find(Refund::class, $id);
    }

    public function findByProviderReference(string $gateway, string $reference): ?Refund
    {
        return $this->entityManager->getRepository(Refund::class)->findOneBy(['gateway' => $gateway, 'providerReference' => $reference]);
    }

    public function ofOrder(int $orderId): array
    {
        /** @var list<Refund> */
        return $this->entityManager->getRepository(Refund::class)->findBy(['orderId' => $orderId], ['id' => 'ASC']);
    }

    public function save(Refund $refund): void
    {
        $this->entityManager->persist($refund);
        $this->entityManager->flush();
    }
}
