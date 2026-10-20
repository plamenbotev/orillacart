<?php

declare(strict_types=1);

namespace OrillaCart\Inventory\Application\Dto;

use OrillaCart\Inventory\Domain\Stockroom;

final readonly class StockroomView
{
    public function __construct(
        public int $id,
        public string $name,
        public string $description,
        public int $minDeliveryDays,
        public int $maxDeliveryDays,
        public bool $active,
        public string $deliveryUnit = 'days',
        public ?int $lowStockThreshold = null,
    ) {}

    public static function fromStockroom(Stockroom $stockroom): self
    {
        $details = $stockroom->details();

        return new self($stockroom->id(), $details->name, $details->description, $details->minDeliveryDays, $details->maxDeliveryDays, $details->active, $details->deliveryUnit->value, $details->lowStockThreshold);
    }
}
