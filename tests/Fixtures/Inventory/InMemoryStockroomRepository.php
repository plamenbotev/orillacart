<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Inventory;

use OrillaCart\Inventory\Domain\Stockroom;
use OrillaCart\Inventory\Domain\StockroomRepository;

final class InMemoryStockroomRepository implements StockroomRepository
{
    /** @var array<int, Stockroom> */
    private array $stockrooms = [];
    private int $nextId = 1;

    /**
     * @param \Closure(Stockroom): void $onRemove what the database's ON DELETE CASCADE would do
     */
    public function __construct(private readonly ?\Closure $onRemove = null) {}

    public function all(): array
    {
        $stockrooms = array_values($this->stockrooms);
        usort($stockrooms, static fn(Stockroom $a, Stockroom $b): int => [$a->details()->name, $a->id()] <=> [$b->details()->name, $b->id()]);

        return $stockrooms;
    }

    public function find(int $id): ?Stockroom
    {
        return $this->stockrooms[$id] ?? null;
    }

    public function save(Stockroom $stockroom): void
    {
        $id = new \ReflectionProperty(Stockroom::class, 'id');
        if ($id->getValue($stockroom) === null) {
            $id->setValue($stockroom, $this->nextId++);
        }
        $this->stockrooms[$stockroom->id()] = $stockroom;
    }

    public function remove(Stockroom $stockroom): void
    {
        unset($this->stockrooms[$stockroom->id()]);
        if ($this->onRemove !== null) {
            ($this->onRemove)($stockroom);
        }
    }
}
