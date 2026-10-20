<?php

declare(strict_types=1);

namespace OrillaCart\Returns\Application\Dto;

/**
 * An order's returns, and what the customer may still send back, until when.
 */
final readonly class OrderReturnsView
{
    /**
     * @param bool                   $accepting  the customer may ask for a return now
     * @param string|null            $until      the last day they may, "2026-11-19"; null when the shop takes no returns or the order has not left
     * @param list<ReturnedItemView> $returnable what may still go back
     * @param list<ReturnView>       $returns    oldest first
     */
    public function __construct(
        public bool $accepting,
        public ?string $until,
        public array $returnable,
        public array $returns,
    ) {}
}
