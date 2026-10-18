<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Payment;

use OrillaCart\Payment\Domain\Payment;
use OrillaCart\Payment\Domain\PaymentRepository;
use OrillaCart\Payment\Domain\PaymentStatus;
use OrillaCart\Tests\Fixtures\ImmediateTransactions;

final class InMemoryPaymentRepository implements PaymentRepository
{
    /** @var array<int, Payment> */
    public array $payments = [];
    private int $nextId = 1;

    public function __construct(private readonly ?ImmediateTransactions $transactions = null) {}

    public function find(int $id, bool $lock = false): ?Payment
    {
        if ($lock) {
            $this->transactions?->requireOpen();
        }

        return $this->payments[$id] ?? null;
    }

    public function findByReference(string $gateway, string $reference): ?Payment
    {
        foreach ($this->payments as $payment) {
            if ($payment->gateway() === $gateway && $payment->reference() === $reference) {
                return $payment;
            }
        }

        return null;
    }

    public function findByTransaction(string $gateway, string $transactionId): ?Payment
    {
        foreach ($this->payments as $payment) {
            if ($payment->gateway() === $gateway && $payment->transactionId() === $transactionId) {
                return $payment;
            }
        }

        return null;
    }

    public function ofOrder(int $orderId): array
    {
        return array_values(array_filter($this->payments, static fn(Payment $payment): bool => $payment->orderId() === $orderId));
    }

    public function openBetween(\DateTimeImmutable $from, \DateTimeImmutable $until, int $limit): array
    {
        return \array_slice(array_values(array_filter($this->payments, static fn(Payment $payment): bool => $payment->status() === PaymentStatus::Pending && $payment->reference() !== null && $payment->createdAt() >= $from && $payment->createdAt() <= $until)), 0, $limit);
    }

    public function save(Payment $payment): void
    {
        $id = new \ReflectionProperty(Payment::class, 'id');
        if ($id->getValue($payment) === null) {
            $id->setValue($payment, $this->nextId++);
        }
        $this->payments[$payment->id()] = $payment;
    }
}
