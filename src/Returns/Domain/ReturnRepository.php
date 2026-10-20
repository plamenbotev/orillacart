<?php

declare(strict_types=1);

namespace OrillaCart\Returns\Domain;

interface ReturnRepository
{
    public function find(int $id): ?ItemReturn;

    /** @return list<ItemReturn> the order's, oldest first */
    public function forOrder(int $orderId): array;

    public function save(ItemReturn $return): void;
}
