<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Domain;

interface RefundRepository
{
    public function find(int $id): ?Refund;

    public function findByProviderReference(string $gateway, string $reference): ?Refund;

    /** @return list<Refund> oldest first */
    public function ofOrder(int $orderId): array;

    public function save(Refund $refund): void;
}
