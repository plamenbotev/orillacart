<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Application\Dto;

/**
 * A page of the stock history, with what moved in all in its time.
 */
final readonly class StockHistoryView
{
    /**
     * @param list<array{id: int, kind: string, item_id: int, product_id: ?int, name: string, sku: ?string, stockroom_id: int, stockroom: string, delta: int, after: int, reason: string, order_id: ?int, user: ?string, at: string}> $movements
     * @param list<array{reason: string, stockroomId: int, stockroom: string, in: int, out: int}>                                                                                                                            $totals
     */
    public function __construct(
        public array $movements,
        public array $totals,
        public int $total,
        public int $page,
        public int $pages,
    ) {}
}
