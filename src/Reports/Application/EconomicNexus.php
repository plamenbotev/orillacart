<?php

declare(strict_types=1);

namespace OrillaCart\Reports\Application;

use OrillaCart\Geography\Application\GeographyService;
use OrillaCart\Jobs\Application\Jobs;
use OrillaCart\Reports\Application\Dto\NexusReport;
use OrillaCart\Reports\Domain\Period;
use OrillaCart\Settings\Application\Currencies;
use OrillaCart\Settings\Application\SettingsService;
use OrillaCart\Shared\Domain\Money;
use OrillaCart\Tax\Application\NexusThresholdSettings;
use OrillaCart\Tax\Application\TaxGroupService;
use OrillaCart\Tax\Application\TaxServices;
use OrillaCart\Tax\Domain\NexusThreshold;
use Psr\Clock\ClockInterface;

/**
 * Measures the shop's sales in each US state against the economic nexus thresholds it set: a state whose
 * threshold its sales crossed in the last twelve months, this calendar year or the last may be owed sales tax,
 * which the shop collects once it registered there.
 */
final readonly class EconomicNexus
{
    private const string COUNTRY = 'US';

    public function __construct(
        private ReportQueries $queries,
        private ShopCalendar $calendar,
        private ClockInterface $clock,
        private SettingsService $settings,
        private NexusThresholdSettings $thresholds,
        private TaxGroupService $groups,
        private TaxServices $taxServices,
        private GeographyService $geography,
        private Jobs $jobs,
        private Currencies $currencies,
    ) {}

    /**
     * Each state's sales and orders in the period, in the shop's currency: sales in another of its currencies at
     * its exchange rate now, as the EU threshold counts them; none is left out.
     *
     * @return array<string, array{sales: string, orders: int}> by state
     */
    private function byState(Period $period, string $currency): array
    {
        $states = [];
        foreach ($this->queries->stateSales($period, self::COUNTRY) as $row) {
            $sales = Money::of($row['sales']);
            if (!$row['rated'] && $row['currency'] !== $currency) {
                // Orders from before rates were kept: at the rate now, while the shop still offers the currency.
                if (!$this->currencies->isOffered($row['currency'])) {
                    continue;
                }
                $sales = $this->currencies->back($sales, $row['currency']);
            }
            $known = $states[$row['state']] ?? ['sales' => '0', 'orders' => 0];
            $states[$row['state']] = ['sales' => (string) Money::of($known['sales'])->add($sales), 'orders' => $known['orders'] + $row['orders']];
        }

        return $states;
    }

    public function report(): NexusReport
    {
        $zone = $this->calendar->timezone();
        $today = $this->clock->now()->setTimezone($zone);
        $windows = [
            'last_twelve_months' => Period::of(self::yearBefore($today)->modify('+1 day')->format('Y-m-d'), $today->format('Y-m-d'), $zone),
            'this_year' => Period::of($today->format('Y-01-01'), $today->format('Y-m-d'), $zone),
            'last_year' => Period::of(((int) $today->format('Y') - 1) . '-01-01', ((int) $today->format('Y') - 1) . '-12-31', $zone),
        ];
        $currency = $this->settings->current()->currency;
        $sales = array_map(fn(Period $period): array => $this->byState($period, $currency), $windows);
        $thresholds = $this->thresholds->current();
        $own = array_column($thresholds->states, null, 'state');
        $names = array_column(array_map(static fn($state): array => ['code' => $state->code, 'name' => $state->name], $this->geography->states(self::COUNTRY)), 'name', 'code');
        $collecting = [];
        foreach ($this->groups->groups() as $group) {
            foreach ($group->rates as $rate) {
                if ($rate->country === self::COUNTRY && $rate->state !== null) {
                    $collecting[$rate->state] = true;
                }
            }
        }

        $states = [];
        foreach (array_unique(array_merge(...array_map(array_keys(...), array_values($sales)))) as $state) {
            $state = (string) $state;
            $limit = $own[$state] ?? $thresholds->everywhere;
            $threshold = new NexusThreshold($limit['sales'], $limit['orders']);
            $measured = array_map(static fn(array $window): array => $window[$state] ?? ['sales' => '0', 'orders' => 0], $sales);
            $states[] = [
                'state' => $state,
                'name' => $names[$state] ?? $state,
                'last_twelve_months' => $measured['last_twelve_months'],
                'this_year' => $measured['this_year'],
                'last_year' => $measured['last_year'],
                'threshold' => ['sales' => $limit['sales'], 'orders' => $limit['orders']],
                'crossed' => array_any($measured, static fn(array $window): bool => $threshold->crossedBy(Money::of($window['sales']), $window['orders'])),
                'collecting' => isset($collecting[$state]),
            ];
        }
        // What may be owed and is not collected first, then by what was sold.
        usort($states, static fn(array $a, array $b): int => [$b['crossed'] && !$b['collecting'], $b['crossed'], Money::of($b['last_twelve_months']['sales'])->compare(Money::of($a['last_twelve_months']['sales']))]
            <=> [$a['crossed'] && !$a['collecting'], $a['crossed'], 0]);
        $waiting = $this->jobs->waiting(ProjectOrders::TYPE);

        return new NexusReport(
            $currency,
            self::days($windows['last_twelve_months']),
            self::days($windows['this_year']),
            self::days($windows['last_year']),
            $states,
            $this->taxServices->active() !== null,
            // Orders not counted yet, those whose counting failed included: the figures are short of them.
            $waiting->due + $waiting->running + $this->jobs->failedCount(ProjectOrders::TYPE) + $this->jobs->pending(RebuildReports::TYPE),
        );
    }

    /**
     * The same day a year before; 29 February's is 28 February, so twelve months back start on 1 March and no day
     * is counted twice or left out.
     */
    public static function yearBefore(\DateTimeImmutable $day): \DateTimeImmutable
    {
        $year = (int) $day->format('Y') - 1;
        $last = (int) $day->setDate($year, (int) $day->format('n'), 1)->format('t');

        return $day->setDate($year, (int) $day->format('n'), min((int) $day->format('j'), $last));
    }

    /** @return array{from: string, to: string} */
    private static function days(Period $period): array
    {
        return ['from' => $period->from->format('Y-m-d'), 'to' => $period->to->format('Y-m-d')];
    }
}
