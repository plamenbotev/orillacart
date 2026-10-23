<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Domain;

interface ConsignmentRepository
{
    public function find(int $id): ?Consignment;

    /** @return list<Consignment> the order's, oldest first */
    public function forOrder(int $orderId): array;

    /** @return list<Consignment> booked with a courier or on the way, the least recently checked first; not those the shop sent itself */
    public function open(int $limit): array;

    /**
     * Orders with a shipment or a parcel whose number contains the text.
     *
     * @return list<int> order ids, up to the limit
     */
    public function orderIdsNumbered(string $text, int $limit): array;

    public function save(Consignment $consignment): void;
}
