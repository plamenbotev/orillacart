<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Tax;

use OrillaCart\Geography\Application\GeographyService;
use OrillaCart\Settings\Application\SettingsService;
use OrillaCart\Settings\Domain\StoreSettings;
use OrillaCart\Tax\Application\Dto\TaxAddress;
use OrillaCart\Tax\Application\Dto\TaxQuery;
use OrillaCart\Tax\Application\TaxCalculator;
use OrillaCart\Tax\Domain\Percentage;
use OrillaCart\Tax\Domain\TaxBasis;
use OrillaCart\Tax\Domain\TaxGroup;
use OrillaCart\Tax\Domain\TaxRateDetails;
use OrillaCart\Tax\Domain\TaxSettings;
use OrillaCart\Tests\Fixtures\Geography\InMemoryCountryRepository;
use OrillaCart\Tests\Fixtures\RecordingDispatcher;
use OrillaCart\Tests\Fixtures\Settings\InMemorySettingsRepository;
use OrillaCart\Tests\Fixtures\Tax\InMemoryTaxGroupRepository;
use OrillaCart\Tests\Fixtures\Tax\InMemoryTaxSettingsRepository;
use PHPUnit\Framework\TestCase;

/**
 * The rebuilt tax rules against what the old plugin returned on the same data (legacy-tax.json, recorded
 * by record-legacy-tax.php from helpers/tax.php). Every case must match the old result, except where the
 * old code had one of the bugs below; those cases must match the old result with that bug corrected.
 *
 * 1. Lost SQL parentheses. With the shop address deciding (the old default) and no shop state, the query
 *    "country = X AND state IS NULL OR state = '' AND group = Y" matched every whole-country rate of the
 *    group in every country: a Bulgarian shop charged 89.5 %. In EU mode the price used that sum while the
 *    cart listed the right rates. Now only the location's country counts, and the total is always the sum
 *    of the listed taxes.
 * 2. Whole-country rates dropped where a state is known. The admin screen saved "all states" as an empty
 *    string, but the query looked for NULL, so a Californian paid the state rate without the federal one,
 *    and a Texan, with no state rate, paid nothing. Now whole-country rates always apply, plus the state's.
 * 3. Outdated reference data: the United Kingdom was still in the EU, so EU mode charged British customers
 *    the shop's VAT. Now they are outside the EU.
 * 4. Nothing taxed before the address was known. With the shipping or billing address deciding, a cart
 *    without one showed prices without tax. Now the shop's own address stands in until it is known.
 */
final class LegacyTaxRulesTest extends TestCase
{
    private const array BASES = [
        'shipping' => TaxBasis::ShippingAddress,
        'billing' => TaxBasis::BillingAddress,
        'shop' => TaxBasis::ShopAddress,
        'eu' => TaxBasis::EuOrigin,
    ];

    /** @var list<array{group: string, country: string, state: string, percent: string, name: string, priority: int}> */
    private array $rates;

    /** @var list<string> */
    private array $legacyEu;

    /** @var list<array<string, mixed>> */
    private array $cases;

    protected function setUp(): void
    {
        $recorded = json_decode((string) file_get_contents(\dirname(__DIR__, 2) . '/Fixtures/Tax/legacy-tax.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($recorded);
        /** @var array{rates: list<array{group: string, country: string, state: string, percent: string, name: string, priority: int}>, eu: list<string>, cases: list<array<string, mixed>>} $recorded */
        $this->rates = $recorded['rates'];
        $this->legacyEu = $recorded['eu'];
        $this->cases = $recorded['cases'];
    }

    public function testTheRebuiltRulesMatchTheOldCodeExceptItsBugs(): void
    {
        $groups = new InMemoryTaxGroupRepository();
        $groupIds = $this->createGroups($groups);
        $corrections = ['unchanged' => 0, 'lost parentheses' => 0, 'whole-country rates' => 0, 'United Kingdom' => 0, 'shop address until known' => 0];

        foreach ($this->cases as $number => $case) {
            /** @var array{mode: string, shop: array{?string, ?string}, default_group: ?string, call: string, group: ?string, rate: float|int, lines: list<int>, address?: array{?string, ?string}, shipping?: array{?string, ?string}, billing?: array{?string, ?string}} $case */
            $basis = self::BASES[$case['mode']];
            [$shipping, $billing] = $case['call'] === 'explicit'
                ? [$case['address'] ?? [null, null], $case['address'] ?? [null, null]]
                : [$case['shipping'] ?? [null, null], $case['billing'] ?? [null, null]];

            $calculator = new TaxCalculator(
                $groups,
                new InMemoryTaxSettingsRepository(new TaxSettings($basis, $case['default_group'] === null ? null : $groupIds[$case['default_group']])),
                new SettingsService(
                    new InMemorySettingsRepository(new StoreSettings(StoreSettings::defaults()->currency, 2, StoreSettings::defaults()->weightUnit, StoreSettings::defaults()->dimensionUnit, $case['shop'][0], $case['shop'][1])),
                    new RecordingDispatcher(),
                    new GeographyService(new InMemoryCountryRepository()),
                ),
                new GeographyService(new InMemoryCountryRepository()),
            );
            $result = $calculator->taxes(new TaxQuery(
                $case['group'] === null ? null : $groupIds[$case['group']],
                new TaxAddress($shipping[0], $shipping[1]),
                new TaxAddress($billing[0], $billing[1]),
            ));

            [$expected, $correction] = $this->expected($case, $basis, $shipping, $billing);
            ++$corrections[$correction];
            $message = \sprintf('Case %d, %s, corrected for: %s', $number, json_encode($case), $correction);
            self::assertSame($expected, array_map(static fn($tax): int => (int) $tax->rateId - 1, $result->taxes), $message);
            self::assertSame($this->sum($expected), $result->total, $message);
        }

        // Most cases match the old code as it was, and every correction was needed somewhere.
        self::assertGreaterThan(1100, $corrections['unchanged']);
        foreach ($corrections as $name => $count) {
            self::assertGreaterThan(0, $count, $name);
        }
    }

    /**
     * The old result, with the one correction it needs, if any.
     *
     * @param array{mode: string, shop: array{?string, ?string}, default_group: ?string, group: ?string, rate: float|int, lines: list<int>} $case
     * @param array{?string, ?string} $shipping
     * @param array{?string, ?string} $billing
     *
     * @return array{list<int>, string} rate indexes in the recorded rates, and the correction applied
     */
    private function expected(array $case, TaxBasis $basis, array $shipping, array $billing): array
    {
        $lines = $case['lines'];
        $location = $this->location($basis, $shipping, $billing, $case['shop']);
        if ($location === null && \in_array($basis, [TaxBasis::ShippingAddress, TaxBasis::BillingAddress], true) && self::locate($case['shop']) !== null) {
            // The old code taxed nothing until the address was known; the shop's own address stands in for it now.
            [$country, $state] = self::locate($case['shop']);
            $group = $case['group'] ?? $case['default_group'];
            $corrected = array_keys(array_filter(
                $this->rates,
                static fn(array $rate): bool => $rate['group'] === $group && $rate['country'] === $country && ($rate['state'] === '' || $rate['state'] === $state),
            ));
            usort($corrected, fn(int $a, int $b): int => [$this->rates[$a]['priority'], $a] <=> [$this->rates[$b]['priority'], $b]);

            return [$corrected, 'shop address until known'];
        }
        $consistent = abs((float) $case['rate'] - (float) $this->sum($lines) / 100) < 1e-9;

        if ($location !== null && $basis === TaxBasis::EuOrigin && $shipping[0] === 'GB' && \in_array('GB', $this->legacyEu, true)) {
            return [[], 'United Kingdom'];
        }
        if ($location === null) {
            // Without a shop country, the broken query still matched every whole-country rate.
            if ($lines !== []) {
                self::assertContains($basis, [TaxBasis::ShopAddress, TaxBasis::EuOrigin], 'The old code taxed a sale without a location.');

                return [[], 'lost parentheses'];
            }

            return [[], 'unchanged'];
        }

        $correction = 'unchanged';
        $inCountry = array_values(array_filter($lines, fn(int $i): bool => $this->rates[$i]['country'] === $location[0]));
        if ($inCountry !== $lines || !$consistent) {
            $correction = 'lost parentheses';
        }

        $group = $case['group'] ?? $case['default_group'];
        $wholeCountry = array_keys(array_filter(
            $this->rates,
            static fn(array $rate): bool => $rate['group'] === $group && $rate['country'] === $location[0] && $rate['state'] === '',
        ));
        $corrected = array_values(array_unique([...$inCountry, ...$wholeCountry]));
        if (\count($corrected) !== \count($inCountry) && $correction === 'unchanged') {
            $correction = 'whole-country rates';
        }
        usort($corrected, fn(int $a, int $b): int => [$this->rates[$a]['priority'], $a] <=> [$this->rates[$b]['priority'], $b]);

        return [$corrected, $correction];
    }

    /**
     * Which address the old mode used, with unknown countries and foreign states dropped as it did.
     *
     * @param array{?string, ?string} $shipping
     * @param array{?string, ?string} $billing
     * @param array{?string, ?string} $shop
     *
     * @return array{string, ?string}|null
     */
    private function location(TaxBasis $basis, array $shipping, array $billing, array $shop): ?array
    {
        return match ($basis) {
            TaxBasis::ShippingAddress => self::locate($shipping),
            TaxBasis::BillingAddress => self::locate($billing),
            TaxBasis::ShopAddress => self::locate($shop),
            TaxBasis::EuOrigin => $shipping[0] === null || \in_array($shipping[0], $this->legacyEu, true) ? self::locate($shop) : null,
            // New since the old plugin, which had no OSS basis.
            TaxBasis::EuDestination => $shipping[0] === null ? self::locate($shop) : (\in_array($shipping[0], $this->legacyEu, true) ? self::locate($shipping) : null),
        };
    }

    /**
     * @param array{?string, ?string} $address
     *
     * @return array{string, ?string}|null
     */
    private static function locate(array $address): ?array
    {
        $country = $address[0] === null ? null : new InMemoryCountryRepository()->find($address[0]);

        return $country === null ? null : [$country->code, $address[1] === null ? null : $country->state($address[1])?->code];
    }

    /**
     * @return array<string, int> group key => id
     */
    private function createGroups(InMemoryTaxGroupRepository $repository): array
    {
        $groups = [];
        foreach (['standard' => 'Standard', 'reduced' => 'Reduced', 'zero' => 'Zero'] as $key => $name) {
            $groups[$key] = new TaxGroup($name);
            $repository->save($groups[$key]);
        }
        // Saved one by one, so rate ids follow the recorded order, as in the old database: id = index + 1.
        foreach ($this->rates as $rate) {
            $groups[$rate['group']]->addRate(new TaxRateDetails(
                $rate['country'],
                $rate['state'] === '' ? null : $rate['state'],
                Percentage::of($rate['percent']),
                $rate['name'],
                $rate['priority'],
            ));
            $repository->save($groups[$rate['group']]);
        }

        return array_map(static fn(TaxGroup $group): int => $group->id(), $groups);
    }

    /**
     * @param list<int> $lines
     */
    private function sum(array $lines): string
    {
        return (string) array_reduce($lines, fn(Percentage $sum, int $i): Percentage => $sum->add(Percentage::of($this->rates[$i]['percent'])), Percentage::zero());
    }
}
