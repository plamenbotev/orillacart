<?php

declare(strict_types=1);

namespace OrillaCart\Reports\Application;

use OrillaCart\Reports\Application\Dto\SalesTotals;
use OrillaCart\Reports\Domain\Interval;
use OrillaCart\Reports\Domain\Period;

/**
 * What the reports read from the facts kept of each order: paid orders in one currency over a period. Amounts are
 * decimal strings.
 */
interface ReportQueries
{
    public function totals(Period $period, string $currency): SalesTotals;

    /**
     * Sales by group of days, keyed by the group's first day (Y-m-d); groups without sales are left out.
     *
     * @return array<string, array{orders: int, gross: string, discounts: string, total: string, refunds: string}>
     */
    public function series(Period $period, Interval $interval, string $currency): array;

    /**
     * Paid orders by payment method, country or shipping method, the largest first.
     *
     * @param 'payment'|'country'|'shipping' $dimension
     *
     * @return list<array{key: string, orders: int, total: string}>
     */
    public function breakdown(Period $period, string $currency, string $dimension, int $limit): array;

    /**
     * Products sold, the best first: by what they brought, or by how many were sold.
     *
     * @param 'net'|'quantity' $sort
     * @param list<int>|null   $only these products alone, to compare them with another period
     *
     * @return list<array{product_id: int, name: string, quantity: int, net: string, orders: int}>
     */
    public function products(Period $period, string $currency, string $sort, int $limit, ?array $only = null): array;

    /**
     * Product categories by what their products brought; a product in two categories counts in both.
     *
     * @param list<int>|null $only these categories alone
     *
     * @return list<array{category_id: int, name: string, quantity: int, net: string, orders: int}>
     */
    public function categories(Period $period, string $currency, int $limit, ?array $only = null): array;

    /**
     * Coupons and automatic discounts by what they took off.
     *
     * @param list<string>|null $only these codes alone
     *
     * @return list<array{code: string, orders: int, amount: string}>
     */
    public function coupons(Period $period, string $currency, int $limit, ?array $only = null): array;

    /**
     * Paid orders of new customers (their first paid order) and of returning ones, by group of days.
     *
     * @return array<string, array{new: int, returning: int, new_total: string, returning_total: string}>
     */
    public function customerSeries(Period $period, Interval $interval, string $currency): array;

    /**
     * The customers who spent the most; guests by their email.
     *
     * @return list<array{customer_id: ?int, name: string, orders: int, total: string}>
     */
    public function topCustomers(Period $period, string $currency, int $limit): array;

    /**
     * The currencies paid orders of the period were in.
     *
     * @return list<string>
     */
    public function currencies(Period $period): array;

    /**
     * The tax of the period's sales by jurisdiction and rate, as returns are filed: what it was charged on, what was
     * sold exempt there, the tax, and what refunds gave back.
     *
     * @return list<array{country: string, region: ?string, level: string, code: string, jurisdiction: string, percent: string, orders: int, taxable: string, exempt: string, tax: string, refunded: string}>
     */
    public function taxes(Period $period, string $currency): array;

    /**
     * The period's sales before tax and orders in each state of a country, for economic nexus, by currency: what
     * the customers paid, before refunds. Rated: the sales are in the shop's currency already, each order at the
     * exchange rate it was priced at; otherwise in the row's currency (the shop's, or orders before rates were kept).
     *
     * @return list<array{currency: string, state: string, sales: string, orders: int, rated: bool}>
     */
    public function stateSales(Period $period, string $country): array;

    /**
     * What the shop sold to consumers in each of these countries before tax (reverse charge sales left out), by
     * currency, for the EU's threshold of distance sales; rated as stateSales() has it.
     *
     * @param list<string> $countries
     *
     * @return list<array{currency: string, country: string, sales: string, orders: int, rated: bool}>
     */
    public function consumerSales(Period $period, array $countries): array;
}
