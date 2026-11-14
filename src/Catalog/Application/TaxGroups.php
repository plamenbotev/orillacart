<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Application;

/**
 * The tax groups a product may be in, which Tax, beside the catalog, keeps.
 */
interface TaxGroups
{
    public function exists(int $id): bool;
}
