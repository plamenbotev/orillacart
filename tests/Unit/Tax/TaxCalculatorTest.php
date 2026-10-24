<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Tax;

use OrillaCart\Geography\Application\GeographyService;
use OrillaCart\Settings\Application\SettingsService;
use OrillaCart\Settings\Domain\StoreSettings;
use OrillaCart\Shared\Domain\RuleViolation;
use OrillaCart\Tax\Application\Dto\AppliedTaxesView;
use OrillaCart\Tax\Application\Dto\AppliedTaxView;
use OrillaCart\Tax\Application\Dto\JurisdictionView;
use OrillaCart\Tax\Application\Dto\SaveTaxGroup;
use OrillaCart\Tax\Application\Dto\SaveTaxRate;
use OrillaCart\Tax\Application\Dto\TaxAddress;
use OrillaCart\Tax\Application\Dto\TaxQuery;
use OrillaCart\Tax\Application\Dto\UpdateTaxSettings;
use OrillaCart\Tax\Application\TaxCalculator;
use OrillaCart\Tax\Application\TaxExemptions;
use OrillaCart\Tax\Application\TaxGroupService;
use OrillaCart\Tax\Application\TaxSettingsService;
use OrillaCart\Tests\Fixtures\Customers\InMemoryAccounts;
use OrillaCart\Tests\Fixtures\Geography\InMemoryCountryRepository;
use OrillaCart\Tests\Fixtures\Payment\FixedExtensions;
use OrillaCart\Tests\Fixtures\RecordingDispatcher;
use OrillaCart\Tests\Fixtures\Settings\InMemorySettingsRepository;
use OrillaCart\Tests\Fixtures\Tax\InMemoryTaxExemptionRepository;
use OrillaCart\Tests\Fixtures\Tax\InMemoryTaxGroupRepository;
use OrillaCart\Tests\Fixtures\Tax\InMemoryTaxSettingsRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class TaxCalculatorTest extends TestCase
{
    private TaxGroupService $service;
    private TaxCalculator $calculator;
    private TaxSettingsService $settings;
    private int $standard;
    private int $reduced;

    protected function setUp(): void
    {
        $groups = new InMemoryTaxGroupRepository();
        $taxSettings = new InMemoryTaxSettingsRepository();
        $geography = new GeographyService(new InMemoryCountryRepository());
        $shop = new InMemorySettingsRepository(new StoreSettings(StoreSettings::defaults()->currency, 2, StoreSettings::defaults()->weightUnit, StoreSettings::defaults()->dimensionUnit, 'BG'));

        $this->service = $service = new TaxGroupService($groups, $taxSettings, $geography, new RecordingDispatcher());
        $this->standard = $service->create(new SaveTaxGroup('Standard'))->id;
        $this->reduced = $service->create(new SaveTaxGroup('Reduced'))->id;
        $service->addRate($this->standard, new SaveTaxRate('BG', null, '20', 'VAT'));
        $service->addRate($this->standard, new SaveTaxRate('DE', null, '19', 'MwSt'));
        $service->addRate($this->reduced, new SaveTaxRate('BG', null, '9', 'VAT'));

        $this->settings = new TaxSettingsService($taxSettings, $groups, new FixedExtensions());
        $this->calculator = new TaxCalculator($groups, $taxSettings, new SettingsService($shop, new RecordingDispatcher(), $geography), $geography, new TaxExemptions(new InMemoryTaxExemptionRepository(), new InMemoryAccounts(), new MockClock()));
    }

    public function testARegionTaxingAtTheSellersAddressTaxesSalesWithinItThereAndOthersAtTheBuyers(): void
    {
        $groups = new InMemoryTaxGroupRepository();
        $taxSettings = new InMemoryTaxSettingsRepository();
        $geography = new GeographyService(new InMemoryCountryRepository());
        $defaults = StoreSettings::defaults();
        $shop = new InMemorySettingsRepository(new StoreSettings($defaults->currency, 2, $defaults->weightUnit, $defaults->dimensionUnit, 'US', shopState: 'TX', shopPostcode: '78701'));
        $service = new TaxGroupService($groups, $taxSettings, $geography, new RecordingDispatcher());
        $group = $service->create(new SaveTaxGroup('Standard'))->id;
        $service->addRate($group, new SaveTaxRate('US', 'TX', '6.25', 'Texas'));
        $service->addRate($group, new SaveTaxRate('US', 'TX', '2', 'Austin', postcodes: ['787*'], code: 'TX-2227013'));
        $service->addRate($group, new SaveTaxRate('US', 'TX', '1', 'Houston', postcodes: ['770*']));
        $service->addRate($group, new SaveTaxRate('US', 'CA', '7.25', 'California'));
        $settings = new TaxSettingsService($taxSettings, $groups, new FixedExtensions());
        $calculator = new TaxCalculator($groups, $taxSettings, new SettingsService($shop, new RecordingDispatcher(), $geography), $geography, new TaxExemptions(new InMemoryTaxExemptionRepository(), new InMemoryAccounts(), new MockClock()));
        $houston = new TaxQuery($group, new TaxAddress('US', 'TX', '77002'));
        $california = new TaxQuery($group, new TaxAddress('US', 'CA', '90001'));

        self::assertSame('7.25', $calculator->taxes($houston)->total, 'at the buyer\'s: Texas and Houston');
        $settings->update(new UpdateTaxSettings('shipping_address', $group, originRegions: ['US-TX']));

        $taxes = $calculator->taxes($houston);
        self::assertSame(['8.25', ['US-TX', 'TX-2227013']], [$taxes->total, array_map(static fn(AppliedTaxView $tax): ?string => $tax->jurisdiction?->code, $taxes->taxes)], 'at the shop\'s: Texas and Austin');
        self::assertSame('7.25', $calculator->taxes($california)->total, 'a sale into another state is the buyer\'s');
        self::assertSame(['US-TX'], $settings->current()->originRegions);
    }

    public function testItemsWithoutAGroupUseTheDefaultGroupOrAreNotTaxed(): void
    {
        $germany = new TaxQuery(shipping: new TaxAddress('DE'));

        self::assertEquals(new AppliedTaxesView('0', []), $this->calculator->taxes($germany));

        $this->settings->update(new UpdateTaxSettings('shipping_address', $this->standard));
        self::assertEquals(new AppliedTaxesView('19', [new AppliedTaxView(2, 'MwSt', '19', jurisdiction: new JurisdictionView('DE', null, 'country', 'DE', 'MwSt'))]), $this->calculator->taxes($germany));
        self::assertSame('0', $this->calculator->taxes(new TaxQuery($this->reduced, new TaxAddress('DE')))->total);
    }

    public function testAGroupThatIsGoneTaxesAsTheDefaultNotAtNothing(): void
    {
        $this->settings->update(new UpdateTaxSettings('shipping_address', $this->standard));

        self::assertSame('20', $this->calculator->taxes(new TaxQuery(999, new TaxAddress('BG')))->total);
    }

    public function testTheBasisPicksTheAddress(): void
    {
        $query = new TaxQuery($this->standard, shipping: new TaxAddress('DE'), billing: new TaxAddress('BG'));

        $this->settings->update(new UpdateTaxSettings('billing_address', null));
        self::assertSame('20', $this->calculator->taxes($query)->total);

        $this->settings->update(new UpdateTaxSettings('shop_address', null));
        self::assertSame('20', $this->calculator->taxes(new TaxQuery($this->standard))->total);
    }

    public function testEuOriginTaxesEuCustomersAtTheShopAndExportsNotAtAll(): void
    {
        $this->settings->update(new UpdateTaxSettings('eu_origin', null));

        self::assertSame('20', $this->calculator->taxes(new TaxQuery($this->standard, new TaxAddress('DE')))->total);
        self::assertSame('20', $this->calculator->taxes(new TaxQuery($this->standard))->total, 'address not known yet');
        self::assertSame('0', $this->calculator->taxes(new TaxQuery($this->standard, new TaxAddress('GB')))->total);
        self::assertSame('0', $this->calculator->taxes(new TaxQuery($this->standard, new TaxAddress('US', 'CA')))->total);
    }

    public function testEuDestinationTaxesEuCustomersAtTheirOwnRate(): void
    {
        $this->settings->update(new UpdateTaxSettings('eu_destination', null));

        self::assertSame('19', $this->calculator->taxes(new TaxQuery($this->standard, new TaxAddress('DE')))->total, 'their country');
        self::assertSame('20', $this->calculator->taxes(new TaxQuery($this->standard))->total, 'the shop’s while the address is not known');
        self::assertSame('0', $this->calculator->taxes(new TaxQuery($this->standard, new TaxAddress('US', 'CA')))->total, 'none outside the EU');
    }

    public function testAGroupGetsTheStandardRateOfEveryEuCountryItHasNoneFor(): void
    {
        // The test geography knows Bulgaria, Germany, Greece and Italy in the EU; BG and DE have rates already.
        self::assertSame(2, $this->service->addEuStandardRates($this->standard));
        self::assertSame(0, $this->service->addEuStandardRates($this->standard), 'once');

        $this->settings->update(new UpdateTaxSettings('eu_destination', $this->standard));
        self::assertSame(['19', '22', '24'], [$this->calculator->taxes(new TaxQuery($this->standard, new TaxAddress('DE')))->total, $this->calculator->taxes(new TaxQuery($this->standard, new TaxAddress('IT')))->total, $this->calculator->taxes(new TaxQuery($this->standard, new TaxAddress('GR')))->total], 'its own rates kept');
    }

    public function testADeletedGroupTaxesNothing(): void
    {
        self::assertSame('0', $this->calculator->taxes(new TaxQuery(999, new TaxAddress('BG')))->total);
    }

    public function testTheDefaultGroupMustExist(): void
    {
        $this->expectException(RuleViolation::class);

        $this->settings->update(new UpdateTaxSettings('shipping_address', 999));
    }
}
