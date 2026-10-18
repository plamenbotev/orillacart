<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Geography;

use OrillaCart\Geography\Application\Dto\CountryView;
use OrillaCart\Geography\Application\Dto\LocationView;
use OrillaCart\Geography\Application\Dto\StateView;
use OrillaCart\Geography\Application\GeographyService;
use OrillaCart\Geography\Domain\UnknownCountry;
use OrillaCart\Geography\Domain\UnknownState;
use OrillaCart\Shared\Domain\NotFound;
use OrillaCart\Tests\Fixtures\Geography\InMemoryCountryRepository;
use PHPUnit\Framework\TestCase;

final class GeographyServiceTest extends TestCase
{
    private GeographyService $geography;

    protected function setUp(): void
    {
        $this->geography = new GeographyService(new InMemoryCountryRepository());
    }

    public function testCountriesAreListedByNameAndSayWhetherAddressesNeedAState(): void
    {
        $countries = $this->geography->countries();

        self::assertSame('Bulgaria', $countries[0]->name);
        self::assertEquals(new CountryView('US', 'United States', false, true), $countries[\count($countries) - 1]);
        self::assertEquals(new CountryView('DE', 'Germany', true, false), $countries[2]);
    }

    public function testStatesOfACountry(): void
    {
        self::assertEquals([new StateView('ON', 'Ontario'), new StateView('QC', 'Quebec')], $this->geography->states('CA'));
        self::assertSame([], $this->geography->states('DE'));

        $this->expectException(NotFound::class);
        $this->geography->states('XX');
    }

    public function testLocatingAnAddressKeepsOnlyWhatIsKnown(): void
    {
        self::assertEquals(new LocationView('US', 'CA', false), $this->geography->locate('US', 'CA'));
        self::assertEquals(new LocationView('US', null, false), $this->geography->locate('US', 'ZZ'));
        self::assertEquals(new LocationView('BG', null, true), $this->geography->locate('BG', 'CA'));
        self::assertNull($this->geography->locate('XX', null));
        self::assertNull($this->geography->locate(null, 'CA'));
        self::assertNull($this->geography->locate('', null));
    }

    public function testRequiringALocationRefusesWhatIsUnknown(): void
    {
        self::assertEquals(new LocationView('IT', 'MI', true), $this->geography->require('IT', 'MI'));

        try {
            $this->geography->require('XX', null);
            self::fail('An unknown country was accepted.');
        } catch (UnknownCountry $e) {
            self::assertSame('XX', $e->countryCode);
        }

        $this->expectException(UnknownState::class);
        $this->geography->require('DE', 'BY');
    }

    public function testAddressesAreWrittenAsTheirCountryWritesThem(): void
    {
        $us = $this->geography->addressLines('Ada', 'Lovelace', '1 Main St', 'Springfield', '62701', 'US', 'CA');
        self::assertSame(['Ada Lovelace', '1 Main St', 'Springfield, CA 62701', 'United States'], $us);

        $de = $this->geography->addressLines('Ada', 'Lovelace', 'Hauptstr. 1', 'Berlin', '10115', 'DE', null);
        self::assertSame(['Ada Lovelace', 'Hauptstr. 1', '10115 Berlin', 'Germany'], $de, 'the default, without the empty state line');

        $ca = $this->geography->addressLines('Ada', '', '1 King St', 'Toronto', 'M5H 1A1', 'CA', 'ON', "{name}\n{street}\n{city}, {state} {postcode}\n{country}");
        self::assertSame(['Ada', '1 King St', 'Toronto, Ontario M5H 1A1', 'Canada'], $ca, 'a layout given instead, with the state named');

        self::assertSame(['Ada', 'Somewhere', 'XX'], $this->geography->addressLines('Ada', '', 'Somewhere', '', '', 'XX', null), 'an unknown country as given; no dangling separators');
    }
}
