<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Shared;

use OrillaCart\Shared\Users\ShopManagers;

final class FixedShopManagers implements ShopManagers
{
    /** @param list<int> $managers */
    public function __construct(public array $managers = [7]) {}

    public function manages(int $userId): bool
    {
        return \in_array($userId, $this->managers, true);
    }
}
