<?php

declare(strict_types=1);

namespace OrillaCart\Inventory\Domain\Event;

/**
 * A stockroom was turned on or off, or deleted: what can be sold of the items it holds may have changed though
 * their counts did not, as whether they can still be owed on backorder.
 */
final readonly class StockroomItemsChanged
{
    /**
     * @param list<string> $items as StockChanged names them: "variation:12"
     */
    public function __construct(public array $items) {}
}
