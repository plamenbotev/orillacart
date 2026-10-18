<?php

declare(strict_types=1);

namespace OrillaCart\Reports\Application\Dto;

/**
 * Who bought in a period: new and returning customers over time, and those who spent the most.
 */
final readonly class CustomersReport
{
    /**
     * @param list<array{date: string, new: int, returning: int, new_total: string, returning_total: string}> $series
     * @param list<array{customer_id: ?int, name: string, orders: int, total: string}>                        $top
     */
    public function __construct(
        public ReportFrame $frame,
        public SalesTotals $totals,
        public ?SalesTotals $previous,
        public array $series,
        public array $top,
    ) {}
}
