<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Application\Dto;

final readonly class OrderPageView
{
    /**
     * @param list<OrderSummaryView> $orders
     */
    public function __construct(
        public array $orders,
        public int $total,
        public int $page,
        public int $pages,
    ) {}
}
