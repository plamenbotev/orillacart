<?php

declare(strict_types=1);

namespace OrillaCart\Reports\Application\Dto;

/**
 * The shop's sales over a period, against the period it is compared with.
 */
final readonly class SalesReport
{
    /**
     * @param list<array{date: string, orders: int, net: string, total: string, refunds: string, previous_orders: ?int, previous_net: ?string}> $series
     *        by group of days; the compared period's group at the same place beside it
     * @param list<array{key: string, orders: int, total: string}> $payments
     * @param list<array{key: string, orders: int, total: string}> $countries
     * @param list<array{key: string, orders: int, total: string}> $shipping
     */
    public function __construct(
        public ReportFrame $frame,
        public SalesTotals $totals,
        public ?SalesTotals $previous,
        public array $series,
        public array $payments,
        public array $countries,
        public array $shipping,
    ) {}
}
