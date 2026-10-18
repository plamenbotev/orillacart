<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Reports;

use OrillaCart\Reports\Application\ReportStore;
use OrillaCart\Reports\Domain\OrderFacts;

final class InMemoryReportStore implements ReportStore
{
    /** @var array<int, OrderFacts> */
    public array $facts = [];

    public function replace(array $orderIds, array $facts): void
    {
        foreach ($orderIds as $id) {
            unset($this->facts[$id]);
        }
        foreach ($facts as $fact) {
            $this->facts[$fact->order->orderId] = $fact;
        }
    }
}
