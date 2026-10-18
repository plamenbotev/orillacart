<?php

declare(strict_types=1);

namespace OrillaCart\Customers\Infrastructure;

use OrillaCart\Catalog\Application\ShopperGroups;
use OrillaCart\Customers\Domain\CustomerGroupRepository;

/**
 * The catalog's prices ask Customers which groups a shopper is in.
 */
final readonly class GroupsOfShoppers implements ShopperGroups
{
    public function __construct(private CustomerGroupRepository $groups) {}

    public function groupIdsOf(?int $customerId): array
    {
        return $customerId === null ? [] : $this->groups->groupIdsOf($customerId);
    }
}
