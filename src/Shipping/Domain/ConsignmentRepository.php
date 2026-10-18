<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Domain;

interface ConsignmentRepository
{
    public function find(int $id): ?Consignment;

    /** @return list<Consignment> the order's, oldest first */
    public function forOrder(int $orderId): array;

    /** @return list<Consignment> booked or on the way, the least recently checked first */
    public function open(int $limit): array;

    public function save(Consignment $consignment): void;
}
