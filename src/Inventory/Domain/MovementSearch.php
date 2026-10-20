<?php

declare(strict_types=1);

namespace OrillaCart\Inventory\Domain;

/**
 * Which movements the shop looks at; null leaves a criterion out.
 */
final readonly class MovementSearch
{
    /**
     * @param list<StockItem> $items the items, such as a product and its variations; none for every item
     */
    public function __construct(
        public array $items = [],
        public ?int $stockroomId = null,
        public ?StockReason $reason = null,
        public ?int $orderId = null,
        public ?\DateTimeImmutable $from = null,
        public ?\DateTimeImmutable $before = null,
    ) {}
}
