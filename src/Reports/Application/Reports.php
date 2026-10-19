<?php

declare(strict_types=1);

namespace OrillaCart\Reports\Application;

use OrillaCart\Jobs\Application\Jobs;
use OrillaCart\Reports\Application\Dto\CustomersReport;
use OrillaCart\Reports\Application\Dto\RankingReport;
use OrillaCart\Reports\Application\Dto\ReportFrame;
use OrillaCart\Reports\Application\Dto\ReportQuery;
use OrillaCart\Reports\Application\Dto\SalesReport;
use OrillaCart\Reports\Domain\Interval;
use OrillaCart\Reports\Domain\InvalidPeriod;
use OrillaCart\Reports\Domain\Period;
use OrillaCart\Settings\Application\SettingsService;
use OrillaCart\Shared\Domain\Money;
use Psr\Clock\ClockInterface;

/**
 * The shop's reports: sales, products, categories, coupons and customers over days of its calendar, each against
 * the days before or the same days a year before. They read the facts the projection keeps of each order, in the
 * shop's currency.
 */
final readonly class Reports
{
    /** The days a report covers when none are asked for. */
    private const int DEFAULT_DAYS = 30;

    public function __construct(
        private ReportQueries $queries,
        private ShopCalendar $calendar,
        private ClockInterface $clock,
        private SettingsService $settings,
        private Jobs $jobs,
    ) {}

    /**
     * @throws InvalidPeriod
     */
    public function sales(ReportQuery $query): SalesReport
    {
        [$frame, $period, $previous, $interval] = $this->frame($query);
        $currency = $frame->currency;
        $current = $this->queries->series($period, $interval, $currency);
        $before = $previous === null ? null : $this->queries->series($previous, $interval, $currency);
        $beforeBuckets = self::comparedBuckets($period, $previous, $interval, $query->compare === ReportQuery::YEAR);
        $series = [];
        foreach ($period->buckets($interval) as $index => $date) {
            $row = $current[$date] ?? null;
            $old = isset($beforeBuckets[$index]) ? ($before[$beforeBuckets[$index]] ?? null) : null;
            $series[] = [
                'date' => $date,
                'orders' => $row['orders'] ?? 0,
                'net' => self::net($row),
                'total' => (string) Money::of($row['total'] ?? '0')->subtract(Money::of($row['refunds'] ?? '0')),
                'refunds' => $row['refunds'] ?? '0',
                'previous_orders' => $before === null || !isset($beforeBuckets[$index]) ? null : ($old['orders'] ?? 0),
                'previous_net' => $before === null || !isset($beforeBuckets[$index]) ? null : self::net($old),
            ];
        }

        return new SalesReport(
            $frame,
            $this->queries->totals($period, $currency),
            $previous === null ? null : $this->queries->totals($previous, $currency),
            $series,
            $this->queries->breakdown($period, $currency, 'payment', $query->limit),
            $this->queries->breakdown($period, $currency, 'country', $query->limit),
            $this->queries->breakdown($period, $currency, 'shipping', $query->limit),
        );
    }

    /**
     * @throws InvalidPeriod
     */
    public function products(ReportQuery $query): RankingReport
    {
        [$frame, $period, $previous] = $this->frame($query);
        $sort = $query->sort === 'quantity' ? 'quantity' : 'net';
        $rows = $this->queries->products($period, $frame->currency, $sort, $query->limit);
        $before = $previous === null || $rows === [] ? [] : self::keyed(
            $this->queries->products($previous, $frame->currency, $sort, \count($rows), array_column($rows, 'product_id')),
            'product_id',
        );

        return new RankingReport($frame, array_map(static fn(array $row): array => $row + [
            'previous_quantity' => $previous === null ? null : ($before[$row['product_id']]['quantity'] ?? 0),
            'previous_net' => $previous === null ? null : ($before[$row['product_id']]['net'] ?? '0'),
        ], $rows));
    }

    /**
     * @throws InvalidPeriod
     */
    public function categories(ReportQuery $query): RankingReport
    {
        [$frame, $period, $previous] = $this->frame($query);
        $rows = $this->queries->categories($period, $frame->currency, $query->limit);
        $before = $previous === null || $rows === [] ? [] : self::keyed(
            $this->queries->categories($previous, $frame->currency, \count($rows), array_column($rows, 'category_id')),
            'category_id',
        );

        return new RankingReport($frame, array_map(static fn(array $row): array => $row + [
            'previous_quantity' => $previous === null ? null : ($before[$row['category_id']]['quantity'] ?? 0),
            'previous_net' => $previous === null ? null : ($before[$row['category_id']]['net'] ?? '0'),
        ], $rows));
    }

    /**
     * @throws InvalidPeriod
     */
    public function coupons(ReportQuery $query): RankingReport
    {
        [$frame, $period, $previous] = $this->frame($query);
        $rows = $this->queries->coupons($period, $frame->currency, $query->limit);
        $before = $previous === null || $rows === [] ? [] : self::keyed(
            $this->queries->coupons($previous, $frame->currency, \count($rows), array_column($rows, 'code')),
            'code',
        );

        return new RankingReport($frame, array_map(static fn(array $row): array => $row + [
            'previous_orders' => $previous === null ? null : ($before[$row['code']]['orders'] ?? 0),
            'previous_amount' => $previous === null ? null : ($before[$row['code']]['amount'] ?? '0'),
        ], $rows));
    }

    /**
     * @throws InvalidPeriod
     */
    public function customers(ReportQuery $query): CustomersReport
    {
        [$frame, $period, $previous, $interval] = $this->frame($query);
        $current = $this->queries->customerSeries($period, $interval, $frame->currency);
        $series = array_map(static fn(string $date): array => ['date' => $date] + ($current[$date] ?? ['new' => 0, 'returning' => 0, 'new_total' => '0', 'returning_total' => '0']), $period->buckets($interval));

        return new CustomersReport(
            $frame,
            $this->queries->totals($period, $frame->currency),
            $previous === null ? null : $this->queries->totals($previous, $frame->currency),
            $series,
            $this->queries->topCustomers($period, $frame->currency, $query->limit),
        );
    }

    /**
     * Counts every order again, in the background: for orders placed before the reports, or after the shop's
     * time zone changed.
     */
    public function rebuild(): void
    {
        // One rebuild at a time: its steps are one job, pushed again with where to go on.
        $this->jobs->push(RebuildReports::TYPE, ['after' => 0], RebuildReports::KEY);
    }

    /**
     * @return array{ReportFrame, Period, ?Period, Interval}
     *
     * @throws InvalidPeriod
     */
    private function frame(ReportQuery $query): array
    {
        $zone = $this->calendar->timezone();
        $today = $this->clock->now()->setTimezone($zone)->format('Y-m-d');
        $to = $query->to ?? $today;
        $period = $query->from === null
            ? Period::endingOn($to, self::DEFAULT_DAYS, $zone)
            : Period::of($query->from, $to, $zone);
        $previous = match ($query->compare) {
            ReportQuery::NONE => null,
            ReportQuery::YEAR => $period->yearBefore(),
            default => $period->previous(),
        };
        $interval = $query->interval === null ? Interval::suiting($period) : Interval::from($query->interval);
        $currency = $this->settings->current()->currency;
        $waiting = $this->jobs->waiting(ProjectOrders::TYPE);

        return [
            new ReportFrame(
                $period->from->format('Y-m-d'),
                $period->to->format('Y-m-d'),
                $interval->value,
                $previous?->from->format('Y-m-d'),
                $previous?->to->format('Y-m-d'),
                $currency,
                array_values(array_diff($this->queries->currencies($period), [$currency])),
                $waiting->due + $waiting->running + $this->jobs->pending(RebuildReports::TYPE),
            ),
            $period,
            $previous,
            $interval,
        ];
    }

    /**
     * The group each of the period's groups is compared with, by position: the one as far into the period before,
     * or the same day, week or month a year before.
     *
     * @return array<int, string> Y-m-d; a group with nothing to compare with is left out
     */
    private static function comparedBuckets(Period $period, ?Period $previous, Interval $interval, bool $yearBefore): array
    {
        if ($previous === null) {
            return [];
        }
        if (!$yearBefore) {
            return $previous->buckets($interval);
        }
        $compared = [];
        foreach ($period->buckets($interval) as $index => $date) {
            $then = $interval->yearBefore(new \DateTimeImmutable($date, $period->from->getTimezone()));
            if ($then !== null) {
                $compared[$index] = $then->format('Y-m-d');
            }
        }

        return $compared;
    }

    /**
     * @param array{orders: int, gross: string, discounts: string, total: string, refunds: string}|null $row
     */
    private static function net(?array $row): string
    {
        return $row === null ? '0' : (string) Money::of($row['gross'])->subtract(Money::of($row['discounts']))->subtract(Money::of($row['refunds']));
    }

    /**
     * @param list<array<string, int|string>> $rows
     *
     * @return array<int|string, array<string, int|string>>
     */
    private static function keyed(array $rows, string $key): array
    {
        $keyed = [];
        foreach ($rows as $row) {
            $keyed[$row[$key] ?? ''] = $row;
        }

        return $keyed;
    }
}
