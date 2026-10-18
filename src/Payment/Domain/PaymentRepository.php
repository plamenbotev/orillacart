<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Domain;

interface PaymentRepository
{
    /**
     * @param bool $lock whether to lock the row until the transaction ends (SELECT … FOR UPDATE); only inside a
     *                   transaction
     */
    public function find(int $id, bool $lock = false): ?Payment;

    public function findByReference(string $gateway, string $reference): ?Payment;

    public function findByTransaction(string $gateway, string $transactionId): ?Payment;

    /** @return list<Payment> */
    public function ofOrder(int $orderId): array;

    /**
     * Payments still pending at a provider, started between the two moments: the least recently checked first.
     *
     * @return list<Payment>
     */
    public function openBetween(\DateTimeImmutable $from, \DateTimeImmutable $until, int $limit): array;

    public function save(Payment $payment): void;
}
