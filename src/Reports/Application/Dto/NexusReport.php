<?php

declare(strict_types=1);

namespace OrillaCart\Reports\Application\Dto;

/**
 * Where the shop may owe US states sales tax: its sales and orders in each state over the last twelve months,
 * this calendar year and the last, against the thresholds it set. States measure over one of these.
 */
final readonly class NexusReport
{
    /**
     * @param array{from: string, to: string}                                                                 $lastTwelveMonths
     * @param array{from: string, to: string}                                                                 $thisYear
     * @param array{from: string, to: string}                                                                 $lastYear
     * @param list<array{state: string, name: string, last_twelve_months: array{sales: string, orders: int}, this_year: array{sales: string, orders: int}, last_year: array{sales: string, orders: int}, threshold: array{sales: ?string, orders: ?int}, crossed: bool, collecting: bool}> $states
     *                                                                                                        the states it sold to: those it may owe but collects nothing in first
     */
    public function __construct(
        public string $currency,
        public array $lastTwelveMonths,
        public array $thisYear,
        public array $lastYear,
        public array $states,
        /** A tax service works out the taxes: it collects in the states the shop registered with it. */
        public bool $taxService,
        /** Orders changed but not counted yet. */
        public int $pending,
    ) {}
}
