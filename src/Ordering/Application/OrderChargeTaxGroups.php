<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Application;

use OrillaCart\Ordering\Domain\OrderRepository;
use OrillaCart\Tax\Application\TaxGroupUse;

/**
 * The charges a tax group taxes on orders still priced again (on hold, waiting to be paid): deleted, the group
 * would leave them at the default group's rates the next time the order is changed.
 */
final readonly class OrderChargeTaxGroups implements TaxGroupUse
{
    public function __construct(private OrderRepository $orders) {}

    public function uses(int $groupId): ?string
    {
        $count = $this->orders->countChargesTaxedBy($groupId);

        return $count === 0 ? null : \sprintf($count === 1 ? '%d charge on an open order' : '%d charges on open orders', $count);
    }
}
