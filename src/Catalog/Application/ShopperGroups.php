<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Application;

/**
 * The customer groups a shopper is in, for their prices. Customers keeps the groups.
 */
interface ShopperGroups
{
    /** @return list<int> none for a guest */
    public function groupIdsOf(?int $customerId): array;
}
