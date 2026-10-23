<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Application;

/**
 * Every area's lookups and marks together, as the order search uses them.
 */
interface OrderLookups
{
    /**
     * @return list<int> ids of the orders any lookup finds by the text; none for text shorter than OrderLookup::MIN_LENGTH
     */
    public function ordersMatching(string $text): array;

    /**
     * @return array<string, string> labels by key
     */
    public function marks(): array;

    /**
     * @return list<int>|null ids of the orders the mark is on; null for no such mark
     */
    public function marked(string $key): ?array;
}
