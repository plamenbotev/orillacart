<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Domain;

interface RefundRepository
{
    /**
     * @param bool $lock whether to lock the row until the transaction ends (SELECT … FOR UPDATE); only inside a
     *                   transaction
     */
    public function find(int $id, bool $lock = false): ?Refund;

    public function findByProviderReference(string $gateway, string $reference): ?Refund;

    /** @return list<Refund> oldest first */
    public function ofOrder(int $orderId): array;

    /** @return list<Refund> refunds a provider accepted to finish later, oldest first */
    public function accepted(int $limit): array;

    public function save(Refund $refund): void;
}
