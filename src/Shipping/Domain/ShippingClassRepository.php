<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Domain;

interface ShippingClassRepository
{
    /** @return list<ShippingClass> by name */
    public function all(): array;

    public function find(int $id): ?ShippingClass;

    public function isNameTaken(string $name, ?int $exceptId = null): bool;

    public function save(ShippingClass $class): void;

    public function remove(ShippingClass $class): void;
}
