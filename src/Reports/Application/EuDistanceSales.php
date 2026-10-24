<?php

declare(strict_types=1);

namespace OrillaCart\Reports\Application;

use OrillaCart\Geography\Application\Dto\CountryView;
use OrillaCart\Geography\Application\GeographyService;
use OrillaCart\Jobs\Application\Jobs;
use OrillaCart\Reports\Application\Dto\EuSalesReport;
use OrillaCart\Reports\Domain\Period;
use OrillaCart\Settings\Application\Currencies;
use OrillaCart\Settings\Application\SettingsService;
use OrillaCart\Shared\Domain\Money;
use OrillaCart\Tax\Application\TaxGroupService;
use OrillaCart\Tax\Application\TaxSettingsService;
use OrillaCart\Tax\Domain\TaxBasis;
use Psr\Clock\ClockInterface;

/**
 * Measures a shop in the EU against the EU's threshold of distance sales (€10,000 a calendar year, the shop's
 * setting): its sales before tax to consumers in other EU countries, this year and the last. Sales to businesses
 * under the reverse charge are not distance sales and are left out.
 */
final readonly class EuDistanceSales
{
    public function __construct(
        private ReportQueries $queries,
        private ShopCalendar $calendar,
        private ClockInterface $clock,
        private SettingsService $settings,
        private Currencies $currencies,
        private TaxSettingsService $taxSettings,
        private TaxGroupService $groups,
        private GeographyService $geography,
        private Jobs $jobs,
    ) {}

    public function report(): EuSalesReport
    {
        $shop = $this->settings->current();
        $tax = $this->taxSettings->current();
        $eu = array_values(array_filter($this->geography->countries(), static fn(CountryView $country): bool => $country->inEu));
        $home = $shop->shopCountry ?? '';
        $others = array_values(array_filter(array_map(static fn(CountryView $country): string => $country->code, $eu), static fn(string $code): bool => $code !== $home));
        $zone = $this->calendar->timezone();
        $today = $this->clock->now()->setTimezone($zone);
        $years = [
            'this_year' => Period::of($today->format('Y-01-01'), $today->format('Y-m-d'), $zone),
            'last_year' => Period::of($today->modify('-1 year')->format('Y-01-01'), $today->modify('-1 year')->format('Y-12-31'), $zone),
        ];
        $empty = static fn(Period $period): array => ['from' => $period->from->format('Y-m-d'), 'to' => $period->to->format('Y-m-d'), 'sales' => '0', 'orders' => 0];
        if (\count($others) === \count($eu)) {
            return new EuSalesReport(false, $shop->currency, $tax->euThreshold, $empty($years['this_year']), $empty($years['last_year']), [], false, false, false, 0);
        }

        $converted = false;
        $totals = [];
        $byCountry = [];
        foreach ($years as $key => $period) {
            $totals[$key] = $empty($period);
            foreach ($this->queries->consumerSales($period, $others) as $row) {
                // Sales in another currency, each order at the rate it was priced at; orders from before rates were
                // kept at the rate now, while the shop still offers the currency.
                $sales = Money::of($row['sales']);
                if ($row['currency'] !== $shop->currency) {
                    $converted = true;
                    if (!$row['rated']) {
                        if (!$this->currencies->isOffered($row['currency'])) {
                            continue;
                        }
                        $sales = $this->currencies->back($sales, $row['currency']);
                    }
                }
                $totals[$key]['sales'] = (string) Money::of($totals[$key]['sales'])->add($sales);
                $totals[$key]['orders'] += $row['orders'];
                $byCountry[$row['country']][$key] = (string) Money::of($byCountry[$row['country']][$key] ?? '0')->add($sales);
            }
        }
        $collecting = [];
        foreach ($this->groups->groups() as $group) {
            foreach ($group->rates as $rate) {
                $collecting[$rate->country] = true;
            }
        }
        $names = array_column(array_map(static fn(CountryView $country): array => ['code' => $country->code, 'name' => $country->name], $eu), 'name', 'code');
        $countries = [];
        foreach ($byCountry as $country => $sales) {
            $countries[] = ['country' => $country, 'name' => $names[$country] ?? $country, 'this_year' => $sales['this_year'] ?? '0', 'last_year' => $sales['last_year'] ?? '0', 'collecting' => isset($collecting[$country])];
        }
        // Those it has no rate for first, then by this year's sales.
        usort($countries, static fn(array $a, array $b): int => [$a['collecting'], Money::of($b['this_year'])->compare(Money::of($a['this_year']))] <=> [$b['collecting'], 0]);
        $threshold = Money::of($tax->euThreshold);
        $waiting = $this->jobs->waiting(ProjectOrders::TYPE);

        return new EuSalesReport(
            true,
            $shop->currency,
            $tax->euThreshold,
            $totals['this_year'],
            $totals['last_year'],
            $countries,
            Money::of($totals['this_year']['sales'])->compare($threshold) > 0 || Money::of($totals['last_year']['sales'])->compare($threshold) > 0,
            \in_array($tax->basis, [TaxBasis::EuOrigin->value, TaxBasis::ShopAddress->value], true),
            $converted,
            $waiting->due + $waiting->running + $this->jobs->pending(RebuildReports::TYPE),
        );
    }
}
