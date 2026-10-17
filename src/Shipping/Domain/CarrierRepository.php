<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Domain;

interface CarrierRepository
{
    /** @return list<Carrier> by position, then name */
    public function all(): array;

    public function find(int $id): ?Carrier;

    public function save(Carrier $carrier): void;

    public function remove(Carrier $carrier): void;
}
