<?php

declare(strict_types=1);

namespace OrillaCart\Inventory\Domain;

interface StockMovementRepository
{
    /** @param list<StockMovement> $movements saved with the stock levels they moved */
    public function add(array $movements): void;

    /**
     * A page of the movements that match, newest first, and how many match in all.
     *
     * @return array{list<StockMovement>, int}
     */
    public function search(MovementSearch $search, int $offset, int $limit): array;

    /**
     * What moved in all, by reason and stockroom.
     *
     * @return list<array{reason: StockReason, stockroomId: int, stockroom: string, in: int, out: int}>
     */
    public function totals(MovementSearch $search): array;
}
