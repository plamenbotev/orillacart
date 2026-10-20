<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Application\Dto;

/**
 * An item a stockroom holds few of: how many (below zero by what it owes) and how few the shop wants to hear of.
 *
 * @param string $kind product, variation or option
 */
final readonly class LowStockView
{
    public function __construct(
        public string $kind,
        public int $id,
        public string $name,
        public ?string $sku,
        public int $stockroomId,
        public string $stockroom,
        public int $quantity,
        public int $threshold,
    ) {}

    /** Names the item in its stockroom: "variation:12@3". */
    public function key(): string
    {
        return "{$this->kind}:{$this->id}@{$this->stockroomId}";
    }
}
