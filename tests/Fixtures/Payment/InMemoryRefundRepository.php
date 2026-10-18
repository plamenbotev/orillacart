<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Payment;

use OrillaCart\Payment\Domain\Refund;
use OrillaCart\Payment\Domain\RefundRepository;
use OrillaCart\Tests\Fixtures\ImmediateTransactions;

final class InMemoryRefundRepository implements RefundRepository
{
    /** @var array<int, Refund> */
    public array $refunds = [];
    private int $nextId = 1;

    public function __construct(private readonly ?ImmediateTransactions $transactions = null) {}

    public function find(int $id, bool $lock = false): ?Refund
    {
        if ($lock) {
            $this->transactions?->requireOpen();
        }

        return $this->refunds[$id] ?? null;
    }

    public function findByProviderReference(string $gateway, string $reference): ?Refund
    {
        foreach ($this->refunds as $refund) {
            if ($refund->gateway() === $gateway && $refund->providerReference() === $reference) {
                return $refund;
            }
        }

        return null;
    }

    public function ofOrder(int $orderId): array
    {
        return array_values(array_filter($this->refunds, static fn(Refund $refund): bool => $refund->orderId() === $orderId));
    }

    public function save(Refund $refund): void
    {
        $id = new \ReflectionProperty(Refund::class, 'id');
        if ($id->getValue($refund) === null) {
            $id->setValue($refund, $this->nextId++);
        }
        $this->refunds[$refund->id()] = $refund;
    }
}
