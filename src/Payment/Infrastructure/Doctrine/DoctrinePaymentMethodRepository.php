<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use OrillaCart\Payment\Domain\PaymentMethod;
use OrillaCart\Payment\Domain\PaymentMethodRepository;

final readonly class DoctrinePaymentMethodRepository implements PaymentMethodRepository
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function all(): array
    {
        /** @var list<PaymentMethod> */
        return $this->entityManager->getRepository(PaymentMethod::class)->findBy([], ['position' => 'ASC', 'gateway' => 'ASC']);
    }

    public function findByGateway(string $gateway): ?PaymentMethod
    {
        return $this->entityManager->getRepository(PaymentMethod::class)->findOneBy(['gateway' => $gateway]);
    }

    public function save(PaymentMethod $method): void
    {
        $this->entityManager->persist($method);
        $this->entityManager->flush();
    }
}
