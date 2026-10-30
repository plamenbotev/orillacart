<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Geography;

use OrillaCart\Geography\Domain\Country;
use OrillaCart\Geography\Domain\CountryRepository;

/**
 * A few real countries, with states where the tax and settings tests need them.
 */
final class InMemoryCountryRepository implements CountryRepository
{
    /** @var array<string, Country> */
    private array $countries = [];

    public function __construct()
    {
        foreach ([
            ['BG', 'BGR', 'Bulgaria', true, []],
            ['CA', 'CAN', 'Canada', false, ['ON' => 'Ontario', 'QC' => 'Quebec']],
            ['CH', 'CHE', 'Switzerland', false, []],
            ['DE', 'DEU', 'Germany', true, []],
            ['FR', 'FRA', 'France', true, []],
            ['GR', 'GRC', 'Greece', true, []],
            ['GB', 'GBR', 'United Kingdom', false, []],
            ['IT', 'ITA', 'Italy', true, ['MI' => 'Milano', 'RM' => 'Roma']],
            ['JP', 'JPN', 'Japan', false, []],
            ['US', 'USA', 'United States', false, ['CA' => 'California', 'NY' => 'New York', 'TX' => 'Texas']],
        ] as [$code, $alpha3, $name, $inEu, $states]) {
            $country = new Country($code, $alpha3, $name, $inEu);
            foreach ($states as $stateCode => $stateName) {
                $country->addState($stateCode, $stateName);
            }
            $this->countries[$code] = $country;
        }
    }

    public function all(): array
    {
        $countries = array_values($this->countries);
        usort($countries, static fn(Country $a, Country $b): int => $a->name <=> $b->name);

        return $countries;
    }

    public function find(string $code): ?Country
    {
        return $this->countries[$code] ?? null;
    }
}
