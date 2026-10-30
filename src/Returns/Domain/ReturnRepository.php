<?php

declare(strict_types=1);

namespace OrillaCart\Returns\Domain;

interface ReturnRepository
{
    /** @param bool $lock read again under a write lock, inside a transaction: for a change to it */
    public function find(int $id, bool $lock = false): ?ItemReturn;

    /** @return list<ItemReturn> the order's, oldest first */
    public function forOrder(int $orderId): array;

    /**
     * @return list<int> ids of the orders with a return still open (requested, approved, received or being refunded)
     */
    public function orderIds(): array;

    public function save(ItemReturn $return): void;
}
