<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Payment;

use OrillaCart\Payment\Domain\Payment;
use OrillaCart\Payment\Domain\PaymentRepository;

final class InMemoryPaymentRepository implements PaymentRepository
{
    /** @var array<int, Payment> */
    public array $payments = [];
    private int $nextId = 1;

    public function find(int $id): ?Payment
    {
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

    public function save(Payment $payment): void
    {
        $id = new \ReflectionProperty(Payment::class, 'id');
        if ($id->getValue($payment) === null) {
            $id->setValue($payment, $this->nextId++);
        }
        $this->payments[$payment->id()] = $payment;
    }
}
