<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Application;

/**
 * Every area's lookups and marks together, with the add-ons' searches and filters, as the order search uses them.
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

    /**
     * The add-ons' order filters whose ids neither the order's facts nor the areas' marks use; the orders they
     * apply to are those marked with their id (MarkedOrder).
     *
     * @return array<string, string> labels by id
     */
    public function addOnFilters(): array;
}
