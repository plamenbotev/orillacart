<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Application;

/**
 * The shipping classes a product may be in, which Shipping, above the catalog, keeps.
 */
interface ShippingClasses
{
    public function exists(int $id): bool;

    /** @return array<int, string> each class's name, by id */
    public function names(): array;
}
