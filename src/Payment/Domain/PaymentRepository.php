<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Domain;

interface PaymentRepository
{
    public function find(int $id): ?Payment;

    public function findByReference(string $gateway, string $reference): ?Payment;

    public function findByTransaction(string $gateway, string $transactionId): ?Payment;

    /** @return list<Payment> */
    public function ofOrder(int $orderId): array;

    public function save(Payment $payment): void;
}
