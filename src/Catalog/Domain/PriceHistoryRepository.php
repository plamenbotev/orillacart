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

    /**
     * Keeps the points, saved together.
     *
     * @param list<PricePoint> $points
     */
    public function add(array $points): void;

    /**
     * Forgets the prices of items the catalog deleted.
     *
     * @param list<string> $items "product:12", "variation:5"
     */
    public function forget(array $items): void;
}
