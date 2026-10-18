<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Infrastructure\Doctrine;

use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use OrillaCart\Payment\Domain\Refund;
use OrillaCart\Payment\Domain\RefundRepository;
use OrillaCart\Payment\Domain\RefundStatus;

final readonly class DoctrineRefundRepository implements RefundRepository
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function find(int $id, bool $lock = false): ?Refund
    {
        $refund = $this->entityManager->find(Refund::class, $id);
        if ($refund !== null && $lock) {
            // Read again under the lock: a copy loaded earlier in the request may be older than the row.
            $this->entityManager->refresh($refund, LockMode::PESSIMISTIC_WRITE);
        }

        return $refund;
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

    public function accepted(int $limit): array
    {
        /** @var list<Refund> */
        return $this->entityManager->getRepository(Refund::class)->findBy(['status' => RefundStatus::Accepted], ['id' => 'ASC'], $limit);
    }

    public function save(Refund $refund): void
    {
        $this->entityManager->persist($refund);
        $this->entityManager->flush();
    }
}
