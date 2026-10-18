<?php

declare(strict_types=1);

namespace OrillaCart\Reports\Application;

use OrillaCart\Reports\Domain\OrderFacts;

/**
 * Where the reports keep what they count of each order.
 */
interface ReportStore
{
    /**
     * Forgets what was kept of the orders, and keeps the facts given instead, all at once.
     *
     * @param list<int>        $orderIds every order the batch covers, also those that are gone
     * @param list<OrderFacts> $facts    of the orders that still exist
     */
    public function replace(array $orderIds, array $facts): void;
}
