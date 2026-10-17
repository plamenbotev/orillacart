<?php

declare(strict_types=1);

namespace OrillaCart\Inventory\Domain;

interface StockroomRepository
{
    /**
     * @return list<Stockroom> by name
     */
    public function all(): array;

    public function find(int $id): ?Stockroom;

    public function save(Stockroom $stockroom): void;

    /**
     * Removes the stockroom with its stock levels.
     */
    public function remove(Stockroom $stockroom): void;
}
