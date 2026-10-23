<?php

declare(strict_types=1);

namespace OrillaCart\Reports\Application\Dto;

/**
 * A shop in the EU against the EU's threshold of distance sales: what it sold to consumers in other EU countries
 * this calendar year and the last. Over €10,000 in either, it charges the customer's country's VAT (OSS) from the
 * sale that crosses it.
 */
final readonly class EuSalesReport
{
    /**
     * @param array{from: string, to: string, sales: string, orders: int}                                       $thisYear
     * @param array{from: string, to: string, sales: string, orders: int}                                       $lastYear
     * @param list<array{country: string, name: string, this_year: string, last_year: string, collecting: bool}> $countries
     *                                                                                                           where it sold, those it has no rate for first
     */
    public function __construct(
        /** The shop is in the EU; otherwise the rest is empty. */
        public bool $applies,
        public string $currency,
        public string $threshold,
        public array $thisYear,
        public array $lastYear,
        public array $countries,
        /** Either year's sales reached the threshold. */
        public bool $crossed,
        /** Taxes go by the shop's address for these customers, as below the threshold. */
        public bool $taxedAtOrigin,
        /** Sales in another currency were counted at the shop's exchange rates now. */
        public bool $converted,
        /** Orders changed but not counted yet. */
        public int $pending,
    ) {}
}
