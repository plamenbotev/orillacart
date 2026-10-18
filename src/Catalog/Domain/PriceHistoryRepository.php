<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Domain;

interface PriceHistoryRepository
{
    /**
     * The latest point of each item.
     *
     * @param list<string> $items
     *
     * @return array<string, PricePoint> by item
     */
    public function latest(array $items): array;

    /**
     * The points of the items recorded since the moment, and the last one before it, oldest first.
     *
     * @param list<string> $items
     *
     * @return list<PricePoint>
     */
    public function since(array $items, \DateTimeImmutable $since): array;

    public function add(PricePoint $point): void;
}
