<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Infrastructure\Doctrine;

use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use OrillaCart\Payment\Domain\Payment;
use OrillaCart\Payment\Domain\PaymentRepository;
use OrillaCart\Payment\Domain\PaymentStatus;

final readonly class DoctrinePaymentRepository implements PaymentRepository
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function find(int $id, bool $lock = false): ?Payment
    {
        $payment = $this->entityManager->find(Payment::class, $id);
        if ($payment !== null && $lock) {
            // Read again under the lock: a copy loaded earlier in the request may be older than the row.
            $this->entityManager->refresh($payment, LockMode::PESSIMISTIC_WRITE);
        }

        return $payment;
    }

    public function findByReference(string $gateway, string $reference): ?Payment
    {
        return $this->entityManager->getRepository(Payment::class)->findOneBy(['gateway' => $gateway, 'reference' => $reference]);
    }

    public function findByTransaction(string $gateway, string $transactionId): ?Payment
    {
        return $this->entityManager->getRepository(Payment::class)->findOneBy(['gateway' => $gateway, 'transactionId' => $transactionId]);
    }

    public function openBetween(\DateTimeImmutable $from, \DateTimeImmutable $until, int $limit): array
    {
        /** @var list<Payment> */
        return $this->entityManager->createQueryBuilder()->select('p')->from(Payment::class, 'p')
            ->where('p.status = :pending')->setParameter('pending', PaymentStatus::Pending)
            ->andWhere('p.reference IS NOT NULL')
            ->andWhere('p.createdAt >= :from AND p.createdAt <= :until')->setParameter('from', $from)->setParameter('until', $until)
            // Never checked first (NULL sorts first ascending), then the longest ago.
            ->orderBy('p.checkedAt', 'ASC')->addOrderBy('p.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function ofOrder(int $orderId): array
    {
        /** @var list<Payment> */
        return $this->entityManager->getRepository(Payment::class)->findBy(['orderId' => $orderId], ['id' => 'ASC']);
    }

    public function save(Payment $payment): void
    {
        $this->entityManager->persist($payment);
        $this->entityManager->flush();
    }

    public function remove(Payment $payment): void
    {
        $this->entityManager->remove($payment);
        $this->entityManager->flush();
    }
}
