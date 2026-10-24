<?php

declare(strict_types=1);

namespace OrillaCart\Reports\Infrastructure\Doctrine;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductCategoryTaxonomy;
use OrillaCart\Reports\Application\Dto\SalesTotals;
use OrillaCart\Reports\Application\ReportQueries;
use OrillaCart\Reports\Domain\Interval;
use OrillaCart\Reports\Domain\Period;
use OrillaCart\Shared\Domain\Money;

/**
 * The reports' SQL, on the facts tables alone: each query reads one table by its (counted, currency, day) index,
 * and names products and categories from WordPress last, for the rows it returns.
 */
final readonly class DbalReportQueries implements ReportQueries
{
    private const string DAY = 'Y-m-d';

    public function __construct(private ReportTables $tables) {}

    public function totals(Period $period, string $currency): SalesTotals
    {
        $orders = $this->tables->orders();
        $row = $this->tables->connection()->fetchAssociative(
            "SELECT COUNT(*) AS orders, COALESCE(SUM(o.items), 0) AS items, COALESCE(SUM(o.gross), 0) AS gross,
                    COALESCE(SUM(o.discount), 0) AS discounts, COALESCE(SUM(o.shipping_total), 0) AS shipping,
                    COALESCE(SUM(o.tax), 0) AS taxes, COALESCE(SUM(o.total), 0) AS total,
                    COUNT(DISTINCT o.customer_key) AS customers, COALESCE(SUM({$this->isFirst('o')}), 0) AS new_customers
             FROM {$orders} o
             WHERE o.counted = 1 AND o.currency = ? AND o.day BETWEEN ? AND ?",
            [$currency, ...$this->days($period)],
        );
        $row = $row === false ? [] : $row;

        return new SalesTotals(
            self::int($row['orders'] ?? 0),
            self::int($row['items'] ?? 0),
            self::money($row['gross'] ?? 0),
            self::money($row['discounts'] ?? 0),
            $this->refunds($period, $currency),
            self::money($row['shipping'] ?? 0),
            self::money($row['taxes'] ?? 0),
            self::money($row['total'] ?? 0),
            self::int($row['customers'] ?? 0),
            self::int($row['new_customers'] ?? 0),
        );
    }

    public function series(Period $period, Interval $interval, string $currency): array
    {
        $series = [];
        foreach ($this->tables->connection()->fetchAllAssociative(
            "SELECT {$this->bucket($interval, 'o.day')} AS bucket, COUNT(*) AS orders, SUM(o.gross) AS gross, SUM(o.discount) AS discounts, SUM(o.total) AS total
             FROM {$this->tables->orders()} o
             WHERE o.counted = 1 AND o.currency = ? AND o.day BETWEEN ? AND ?
             GROUP BY bucket",
            [$currency, ...$this->days($period)],
        ) as $row) {
            $series[self::string($row['bucket'])] = ['orders' => self::int($row['orders']), 'gross' => self::money($row['gross']), 'discounts' => self::money($row['discounts']), 'total' => self::money($row['total']), 'refunds' => '0'];
        }
        foreach ($this->tables->connection()->fetchAllAssociative(
            "SELECT {$this->bucket($interval, 'r.day')} AS bucket, SUM(r.amount) AS refunds
             FROM {$this->tables->refunds()} r
             WHERE r.currency = ? AND r.day BETWEEN ? AND ?
             GROUP BY bucket",
            [$currency, ...$this->days($period)],
        ) as $row) {
            $bucket = self::string($row['bucket']);
            $series[$bucket] = ['refunds' => self::money($row['refunds'])] + ($series[$bucket] ?? ['orders' => 0, 'gross' => '0', 'discounts' => '0', 'total' => '0']);
        }
        ksort($series);

        return $series;
    }

    public function breakdown(Period $period, string $currency, string $dimension, int $limit): array
    {
        $column = match ($dimension) {
            'payment' => 'o.payment',
            'country' => 'o.country',
            'shipping' => 'o.shipping',
        };

        return array_map(static fn(array $row): array => [
            'key' => self::string($row['k']),
            'orders' => self::int($row['orders']),
            'total' => self::money($row['total']),
        ], $this->tables->connection()->fetchAllAssociative(
            "SELECT {$column} AS k, COUNT(*) AS orders, SUM(o.total) AS total
             FROM {$this->tables->orders()} o
             WHERE o.counted = 1 AND o.currency = ? AND o.day BETWEEN ? AND ?
             GROUP BY {$column}
             ORDER BY total DESC, k
             LIMIT " . max(1, $limit),
            [$currency, ...$this->days($period)],
        ));
    }

    public function products(Period $period, string $currency, string $sort, int $limit, ?array $only = null): array
    {
        if ($only === []) {
            return [];
        }
        $order = $sort === 'quantity' ? 'quantity DESC, net DESC' : 'net DESC, quantity DESC';
        $filter = $only === null ? '' : ' AND l.product_id IN (' . implode(', ', array_fill(0, \count($only), '?')) . ')';
        $rows = $this->tables->connection()->fetchAllAssociative(
            "SELECT l.product_id, MAX(l.name) AS line_name, SUM(l.quantity) AS quantity, SUM(l.net - l.refunded) AS net, COUNT(DISTINCT l.order_id) AS orders
             FROM {$this->tables->lines()} l
             WHERE l.counted = 1 AND l.currency = ? AND l.day BETWEEN ? AND ?{$filter}
             GROUP BY l.product_id
             ORDER BY {$order}, l.product_id
             LIMIT " . max(1, $limit),
            [$currency, ...$this->days($period), ...($only ?? [])],
        );
        $names = $this->postTitles(array_map(static fn(array $row): int => self::int($row['product_id']), $rows));

        return array_map(static fn(array $row): array => [
            'product_id' => self::int($row['product_id']),
            // A product deleted since keeps the name its last line had.
            'name' => $names[self::int($row['product_id'])] ?? self::string($row['line_name']),
            'quantity' => self::int($row['quantity']),
            'net' => self::money($row['net']),
            'orders' => self::int($row['orders']),
        ], $rows);
    }

    public function categories(Period $period, string $currency, int $limit, ?array $only = null): array
    {
        if ($only === []) {
            return [];
        }
        $filter = $only === null ? '' : ' AND tt.term_id IN (' . implode(', ', array_fill(0, \count($only), '?')) . ')';

        return array_map(static fn(array $row): array => [
            'category_id' => self::int($row['category_id']),
            'name' => self::string($row['name']),
            'quantity' => self::int($row['quantity']),
            'net' => self::money($row['net']),
            'orders' => self::int($row['orders']),
        ], $this->tables->connection()->fetchAllAssociative(
            "SELECT tt.term_id AS category_id, MAX(t.name) AS name, SUM(l.quantity) AS quantity, SUM(l.net - l.refunded) AS net, COUNT(DISTINCT l.order_id) AS orders
             FROM {$this->tables->lines()} l
             JOIN {$this->tables->wordpress('term_relationships')} tr ON tr.object_id = l.product_id
             JOIN {$this->tables->wordpress('term_taxonomy')} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = ?
             JOIN {$this->tables->wordpress('terms')} t ON t.term_id = tt.term_id
             WHERE l.counted = 1 AND l.currency = ? AND l.day BETWEEN ? AND ?{$filter}
             GROUP BY tt.term_id
             ORDER BY net DESC, tt.term_id
             LIMIT " . max(1, $limit),
            [ProductCategoryTaxonomy::NAME, $currency, ...$this->days($period), ...($only ?? [])],
        ));
    }

    public function coupons(Period $period, string $currency, int $limit, ?array $only = null): array
    {
        if ($only === []) {
            return [];
        }
        $filter = $only === null ? '' : ' AND c.code IN (' . implode(', ', array_fill(0, \count($only), '?')) . ')';

        return array_map(static fn(array $row): array => [
            'code' => self::string($row['code']),
            'orders' => self::int($row['orders']),
            'amount' => self::money($row['amount']),
        ], $this->tables->connection()->fetchAllAssociative(
            "SELECT c.code, COUNT(DISTINCT c.order_id) AS orders, SUM(c.amount) AS amount
             FROM {$this->tables->coupons()} c
             WHERE c.counted = 1 AND c.currency = ? AND c.day BETWEEN ? AND ?{$filter}
             GROUP BY c.code
             ORDER BY amount DESC, c.code
             LIMIT " . max(1, $limit),
            [$currency, ...$this->days($period), ...($only ?? [])],
        ));
    }

    public function customerSeries(Period $period, Interval $interval, string $currency): array
    {
        $series = [];
        foreach ($this->tables->connection()->fetchAllAssociative(
            "SELECT x.bucket, SUM(x.first) AS new_orders, SUM(1 - x.first) AS returning_orders,
                    SUM(x.first * x.total) AS new_total, SUM((1 - x.first) * x.total) AS returning_total
             FROM (
                 SELECT {$this->bucket($interval, 'o.day')} AS bucket, {$this->isFirst('o')} AS first, o.total
                 FROM {$this->tables->orders()} o
                 WHERE o.counted = 1 AND o.currency = ? AND o.day BETWEEN ? AND ?
             ) x
             GROUP BY x.bucket",
            [$currency, ...$this->days($period)],
        ) as $row) {
            $series[self::string($row['bucket'])] = [
                'new' => self::int($row['new_orders']),
                'returning' => self::int($row['returning_orders']),
                'new_total' => self::money($row['new_total']),
                'returning_total' => self::money($row['returning_total']),
            ];
        }

        return $series;
    }

    public function topCustomers(Period $period, string $currency, int $limit): array
    {
        return array_map(static fn(array $row): array => [
            'customer_id' => $row['customer_id'] === null ? null : self::int($row['customer_id']),
            'name' => self::string($row['name']),
            'orders' => self::int($row['orders']),
            'total' => self::money($row['total']),
        ], $this->tables->connection()->fetchAllAssociative(
            "SELECT o.customer_key, MAX(o.customer_id) AS customer_id, MAX(o.customer_name) AS name, COUNT(*) AS orders, SUM(o.total) AS total
             FROM {$this->tables->orders()} o
             WHERE o.counted = 1 AND o.currency = ? AND o.day BETWEEN ? AND ?
             GROUP BY o.customer_key
             ORDER BY total DESC, o.customer_key
             LIMIT " . max(1, $limit),
            [$currency, ...$this->days($period)],
        ));
    }

    public function currencies(Period $period): array
    {
        return array_map(self::string(...), $this->tables->connection()->fetchFirstColumn(
            "SELECT DISTINCT o.currency FROM {$this->tables->orders()} o WHERE o.counted = 1 AND o.day BETWEEN ? AND ? ORDER BY o.currency",
            $this->days($period),
        ));
    }

    private function refunds(Period $period, string $currency): string
    {
        return self::money($this->tables->connection()->fetchOne(
            "SELECT COALESCE(SUM(r.amount), 0) FROM {$this->tables->refunds()} r WHERE r.currency = ? AND r.day BETWEEN ? AND ?",
            [$currency, ...$this->days($period)],
        ));
    }

    /**
     * 1 for an order that is its customer's first paid one, else 0: no paid order of theirs came before it. The
     * (customer_key, placed_at) index answers it.
     */
    private function isFirst(string $alias): string
    {
        return "(CASE WHEN EXISTS (
                    SELECT 1 FROM {$this->tables->orders()} earlier
                    WHERE earlier.customer_key = {$alias}.customer_key AND earlier.counted = 1
                      AND (earlier.placed_at < {$alias}.placed_at OR (earlier.placed_at = {$alias}.placed_at AND earlier.order_id < {$alias}.order_id))
                ) THEN 0 ELSE 1 END)";
    }

    /** The first day of the group a day is in, as Y-m-d; weeks start on Monday. */
    private function bucket(Interval $interval, string $column): string
    {
        return match ($interval) {
            Interval::Day => "DATE_FORMAT({$column}, '%Y-%m-%d')",
            Interval::Week => "DATE_FORMAT(DATE_SUB({$column}, INTERVAL WEEKDAY({$column}) DAY), '%Y-%m-%d')",
            Interval::Month => "DATE_FORMAT({$column}, '%Y-%m-01')",
        };
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, string>
     */
    private function postTitles(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $titles = [];
        foreach ($this->tables->connection()->fetchAllAssociative(
            "SELECT ID, post_title FROM {$this->tables->wordpress('posts')} WHERE ID IN (" . implode(', ', array_fill(0, \count($ids), '?')) . ')',
            $ids,
        ) as $row) {
            $titles[self::int($row['ID'])] = self::string($row['post_title']);
        }

        return $titles;
    }

    public function taxes(Period $period, string $currency): array
    {
        return array_map(static fn(array $row): array => [
            'country' => self::string($row['country']),
            'region' => $row['region'] === null ? null : self::string($row['region']),
            'level' => self::string($row['level']),
            'code' => self::string($row['code']),
            'jurisdiction' => self::string($row['jurisdiction']),
            'percent' => self::money($row['percent']),
            'orders' => self::int($row['orders']),
            'taxable' => self::money($row['taxable']),
            'exempt' => self::money($row['exempt']),
            'tax' => self::money($row['tax']),
            'refunded' => self::money($row['refunded']),
        ], $this->tables->connection()->fetchAllAssociative(
            // A refund's rows count the tax it gave back on its own day, not as an order of that period.
            "SELECT t.country, t.region, t.level, t.code, MAX(t.jurisdiction) AS jurisdiction, t.percent, COUNT(DISTINCT CASE WHEN t.taxable <> 0 OR t.amount <> 0 THEN t.order_id END) AS orders,
                    SUM(CASE WHEN t.exempt = 1 THEN 0 ELSE t.taxable END) AS taxable, SUM(CASE WHEN t.exempt = 1 THEN t.taxable ELSE 0 END) AS exempt,
                    SUM(t.amount) AS tax, SUM(t.refunded) AS refunded
             FROM {$this->tables->taxes()} t
             WHERE t.counted = 1 AND t.currency = ? AND t.day BETWEEN ? AND ?
             GROUP BY t.country, t.region, t.level, t.code, t.percent
             ORDER BY t.country, t.region, FIELD(t.level, 'country', 'region', 'local'), t.code, t.percent",
            [$currency, ...$this->days($period)],
        ));
    }

    public function stateSales(Period $period, string $country): array
    {
        return array_map(static fn(array $row): array => [
            'currency' => self::string($row['currency']),
            'state' => self::string($row['region']),
            'sales' => self::money($row['sales']),
            'orders' => self::int($row['orders']),
        ], $this->tables->connection()->fetchAllAssociative(
            "SELECT currency, region, COUNT(*) AS orders, SUM(total - tax) AS sales
             FROM {$this->tables->orders()}
             WHERE counted = 1 AND country = ? AND region IS NOT NULL AND day BETWEEN ? AND ?
             GROUP BY currency, region
             ORDER BY region, currency",
            [$country, ...$this->days($period)],
        ));
    }

    public function consumerSales(Period $period, array $countries): array
    {
        if ($countries === []) {
            return [];
        }

        return array_map(static fn(array $row): array => [
            'currency' => self::string($row['currency']),
            'country' => self::string($row['country']),
            'sales' => self::money($row['sales']),
            'orders' => self::int($row['orders']),
        ], $this->tables->connection()->fetchAllAssociative(
            "SELECT currency, country, COUNT(*) AS orders, SUM(total - tax) AS sales
             FROM {$this->tables->orders()}
             WHERE counted = 1 AND reverse_charge = 0 AND day BETWEEN ? AND ? AND country IN (?)
             GROUP BY currency, country
             ORDER BY country, currency",
            [...$this->days($period), $countries],
            [ParameterType::STRING, ParameterType::STRING, ArrayParameterType::STRING],
        ));
    }

    /** @return array{string, string} */
    private function days(Period $period): array
    {
        return [$period->from->format(self::DAY), $period->to->format(self::DAY)];
    }

    private static function money(mixed $value): string
    {
        return (string) Money::of(is_numeric($value) ? (string) $value : '0');
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function string(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }
}
