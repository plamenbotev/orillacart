<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use OrillaCart\Payment\Domain\Payment;
use OrillaCart\Payment\Domain\PaymentRepository;

final readonly class DoctrinePaymentRepository implements PaymentRepository
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function find(int $id): ?Payment
    {
        return $this->entityManager->find(Payment::class, $id);
    }

    public function findByReference(string $gateway, string $reference): ?Payment
    {
        return $this->entityManager->getRepository(Payment::class)->findOneBy(['gateway' => $gateway, 'reference' => $reference]);
    }

    public function findByTransaction(string $gateway, string $transactionId): ?Payment
    {
        return $this->entityManager->getRepository(Payment::class)->findOneBy(['gateway' => $gateway, 'transactionId' => $transactionId]);
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
}
