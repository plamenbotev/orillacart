<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Application\Dto;

use OrillaCart\Inventory\Application\Dto\StockroomView;

final readonly class StockSheetView
{
    /**
     * @param list<StockroomView> $stockrooms the sheet's columns: every stockroom, or the one asked for
     * @param list<StockRowView>  $rows
     * @param int                 $total      products (with their variations) or options that match, on every page
     */
    public function __construct(
        public array $stockrooms,
        public array $rows,
        public int $total,
        public int $page,
        public int $pages,
    ) {}
}
