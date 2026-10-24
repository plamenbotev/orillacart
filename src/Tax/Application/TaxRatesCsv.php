<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Application;

use OrillaCart\Exchange\Application\CsvFile;
use OrillaCart\Geography\Application\GeographyService;
use OrillaCart\Shared\Domain\PostcodePattern;
use OrillaCart\Shared\Domain\RuleViolation;
use OrillaCart\Tax\Application\Dto\ImportTaxRates;
use OrillaCart\Tax\Application\Dto\TaxGroupView;
use OrillaCart\Tax\Domain\Event\TaxRatesChanged;
use OrillaCart\Tax\Domain\Percentage;
use OrillaCart\Tax\Domain\TaxGroup;
use OrillaCart\Tax\Domain\TaxGroupNotFound;
use OrillaCart\Tax\Domain\TaxGroupRepository;
use OrillaCart\Tax\Domain\TaxRateDetails;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * A tax group's rates as CSV, in WooCommerce's columns (country code, state code, postcodes, cities, rate, name,
 * priority, compound, shipping, tax class) and then the code the shop files the tax under, so a shop moving from
 * WooCommerce brings its rates. Postcodes and cities are separated by semicolons; WooCommerce's ranges
 * ("12345...12399") read as ranges. The tax class is the group's name on export and is not read on import:
 * the rates go into the group chosen.
 *
 * Every row is checked before any is saved. WooCommerce charges one rate per priority, the first that matches;
 * OrillaCart adds up every rate that applies, so a file where a rate for a whole country or state and a narrower
 * one share a priority is refused rather than charged twice.
 */
final readonly class TaxRatesCsv
{
    public const int MAX_ROWS = TaxGroup::MAX_RATES;

    public const array HEADER = ['Country code', 'State code', 'Postcode / ZIP', 'City', 'Rate %', 'Tax name', 'Priority', 'Compound', 'Shipping', 'Tax class', 'Tax code'];

    public function __construct(
        private TaxGroupRepository $groups,
        private GeographyService $geography,
        private EventDispatcherInterface $events,
    ) {}

    /**
     * @throws TaxGroupNotFound
     */
    public function export(int $groupId): string
    {
        $group = $this->groups->find($groupId) ?? throw new TaxGroupNotFound($groupId);
        $stream = fopen('php://temp', 'w+');
        \assert(\is_resource($stream));
        $csv = new CsvFile($stream);
        $csv->start(self::HEADER, self::HEADER);
        foreach ($group->rates() as $rate) {
            $details = $rate->details();
            $csv->row([
                $details->country,
                $details->state ?? '',
                implode(';', array_map(strval(...), $details->postcodes)),
                implode(';', $details->cities),
                (string) $details->percent,
                $details->name,
                (string) $details->priority,
                $details->compound ? '1' : '0',
                $details->shipping ? '1' : '0',
                $group->name(),
                $details->code ?? '',
            ]);
        }
        $csv->finish();
        rewind($stream);
        $text = (string) stream_get_contents($stream);
        fclose($stream);

        return $text;
    }

    /**
     * Adds the file's rates to the group, or puts them in place of its rates.
     *
     * @throws TaxGroupNotFound
     * @throws RuleViolation    naming the row of the first rate that cannot be, or two that would be charged twice
     */
    public function import(int $groupId, ImportTaxRates $command): TaxGroupView
    {
        $group = $this->groups->find($groupId) ?? throw new TaxGroupNotFound($groupId);
        $rates = $this->read($command->csv);
        if (!$command->replace && \count($group->rates()) + \count($rates) > TaxGroup::MAX_RATES) {
            throw new RuleViolation('A tax group holds up to %d rates; this file would take it past that.', 'orillacart_too_many_tax_rates', values: [TaxGroup::MAX_RATES]);
        }
        self::refuseOverlaps($command->replace ? $rates : [...array_map(static fn($rate): array => [0, $rate->details()], $group->rates()), ...$rates]);

        if ($command->replace) {
            foreach ($group->rates() as $rate) {
                $group->removeRate($rate->id());
            }
        }
        foreach ($rates as [, $details]) {
            $group->addRate($details);
        }
        // One save: the rates removed and added together, or none of them.
        $this->groups->save($group);
        $this->events->dispatch(new TaxRatesChanged($groupId));

        return TaxGroupView::fromGroup($group);
    }

    /**
     * @return list<array{int, TaxRateDetails}> each rate with the row it is on
     */
    private function read(string $csv): array
    {
        $stream = fopen('php://temp', 'w+');
        \assert(\is_resource($stream));
        fwrite($stream, preg_replace('/^\x{FEFF}/u', '', $csv) ?? $csv);
        rewind($stream);
        $rates = [];
        $row = 0;
        try {
            while (($cells = fgetcsv($stream, 0, ',', '"', '')) !== false) {
                ++$row;
                if ($cells === [null] || $cells === ['']) {
                    continue;
                }
                $cells = array_map(static fn(?string $cell): string => self::cell($cell ?? ''), $cells);
                if ($row === 1 && preg_match('/^[A-Z]{2}$/', strtoupper($cells[0])) !== 1) {
                    continue;
                }
                if (\count($rates) >= self::MAX_ROWS) {
                    throw new RuleViolation('A file can hold up to %d rates.', 'orillacart_too_many_tax_rates', values: [self::MAX_ROWS]);
                }
                try {
                    $rates[] = [$row, $this->rate($cells)];
                } catch (RuleViolation $refused) {
                    throw new RuleViolation('Row %1$d: %2$s', $refused->errorCode, $refused, values: [$row, $refused->text]);
                }
            }
        } finally {
            fclose($stream);
        }
        if ($rates === []) {
            throw new RuleViolation('The file has no rates: use the columns of an exported file.', 'orillacart_invalid_tax_rates_file');
        }

        return $rates;
    }

    /**
     * @param list<string> $cells
     */
    private function rate(array $cells): TaxRateDetails
    {
        [$country, $state, $postcodes, $cities, $percent, $name, $priority, $compound, $shipping] = array_pad($cells, 9, '');
        $code = $cells[10] ?? '';
        $country = strtoupper($country);
        if ($country === '' || $country === '*') {
            throw new RuleViolation('a rate needs its country: OrillaCart has no rate for every country.', 'orillacart_invalid_country');
        }
        $state = strtoupper($state);
        $location = $this->geography->require($country, $state === '' || $state === '*' ? null : $state);
        if (preg_match('/^\d{1,3}(\.\d{1,4})?$/', $percent) !== 1) {
            throw new RuleViolation('"%s" is not a rate: use a number such as 7.25.', 'orillacart_invalid_percent', values: [$percent]);
        }
        $priority = $priority === '' ? '0' : $priority;
        if (preg_match('/^\d{1,4}$/', $priority) !== 1 || (int) $priority > 1000) {
            throw new RuleViolation('"%s" is not a priority from 0 to 1000.', 'orillacart_invalid_priority', values: [$priority]);
        }

        return new TaxRateDetails(
            $location->country,
            $location->state,
            Percentage::of($percent),
            mb_substr($name, 0, TaxRateDetails::MAX_NAME_LENGTH),
            (int) $priority,
            array_map(PostcodePattern::of(...), self::list($postcodes, TaxRateDetails::MAX_POSTCODES)),
            self::flag($compound, false),
            $code === '' ? null : $code,
            array_values(array_unique(array_map(TaxRateDetails::city(...), self::list($cities, TaxRateDetails::MAX_CITIES)))),
            self::flag($shipping, true),
        );
    }

    /**
     * @param list<array{int, TaxRateDetails}> $rates each with its row; 0 for a rate the group has
     */
    private static function refuseOverlaps(array $rates): void
    {
        foreach ($rates as $index => [$row, $rate]) {
            foreach (\array_slice($rates, 0, $index) as [$otherRow, $other]) {
                if (self::overlap($rate, $other)) {
                    throw new RuleViolation('Row %1$d and %2$s apply at the same places with priority %3$d. WooCommerce charges only one of them; OrillaCart would charge both. Keep one, or give each its own places.', 'orillacart_overlapping_tax_rates', values: [$row, $otherRow === 0 ? 'a rate the group has' : "row {$otherRow}", $rate->priority]);
                }
            }
        }
    }

    /**
     * Whether two rates of one priority would both be charged somewhere: one for a whole country and any other
     * there, one for a whole state and any other that may be in it, or the same places twice.
     */
    private static function overlap(TaxRateDetails $a, TaxRateDetails $b): bool
    {
        if ($a->country !== $b->country || $a->priority !== $b->priority) {
            return false;
        }
        $narrow = static fn(TaxRateDetails $rate): bool => $rate->postcodes !== [] || $rate->cities !== [];
        $wholeCountry = static fn(TaxRateDetails $rate): bool => $rate->state === null && !$narrow($rate);
        $wholeState = static fn(TaxRateDetails $rate): bool => $rate->state !== null && !$narrow($rate);
        if ($wholeCountry($a) || $wholeCountry($b)) {
            return true;
        }
        if ($wholeState($a) || $wholeState($b)) {
            return $a->state === $b->state || $a->state === null || $b->state === null;
        }
        $places = static fn(TaxRateDetails $rate): string => $rate->state . '|' . implode(';', array_map(strval(...), $rate->postcodes)) . '|' . implode(';', $rate->cities);

        return $places($a) === $places($b);
    }

    /**
     * A cell as typed: the apostrophe an export puts before what looks like a formula comes off.
     */
    private static function cell(string $cell): string
    {
        $cell = trim($cell);

        return str_starts_with($cell, "'") && preg_match(CsvFile::FORMULA, substr($cell, 1)) === 1 ? substr($cell, 1) : $cell;
    }

    /**
     * @return list<string>
     */
    private static function list(string $cell, int $max): array
    {
        $values = array_values(array_filter(array_map(trim(...), explode(';', $cell)), static fn(string $value): bool => $value !== '' && $value !== '*'));
        if (\count($values) > $max) {
            throw new RuleViolation('a rate can list up to %d of them.', 'orillacart_too_many_postcodes', values: [$max]);
        }

        return $values;
    }

    private static function flag(string $cell, bool $empty): bool
    {
        return match (strtolower($cell)) {
            '' => $empty,
            '1', 'yes', 'true' => true,
            '0', 'no', 'false' => false,
            default => throw new RuleViolation('"%s" is not 1 or 0.', 'orillacart_invalid_flag', values: [$cell]),
        };
    }
}
