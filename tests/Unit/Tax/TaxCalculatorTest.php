<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Tax;

use OrillaCart\Geography\Application\GeographyService;
use OrillaCart\Settings\Application\SettingsService;
use OrillaCart\Settings\Domain\StoreSettings;
use OrillaCart\Shared\Domain\RuleViolation;
use OrillaCart\Tax\Application\Dto\AppliedTaxesView;
use OrillaCart\Tax\Application\Dto\AppliedTaxView;
use OrillaCart\Tax\Application\Dto\SaveTaxGroup;
use OrillaCart\Tax\Application\Dto\SaveTaxRate;
use OrillaCart\Tax\Application\Dto\TaxAddress;
use OrillaCart\Tax\Application\Dto\TaxQuery;
use OrillaCart\Tax\Application\Dto\UpdateTaxSettings;
use OrillaCart\Tax\Application\TaxCalculator;
use OrillaCart\Tax\Application\TaxGroupService;
use OrillaCart\Tax\Application\TaxSettingsService;
use OrillaCart\Tests\Fixtures\Geography\InMemoryCountryRepository;
use OrillaCart\Tests\Fixtures\RecordingDispatcher;
use OrillaCart\Tests\Fixtures\Settings\InMemorySettingsRepository;
use OrillaCart\Tests\Fixtures\Tax\InMemoryTaxGroupRepository;
use OrillaCart\Tests\Fixtures\Tax\InMemoryTaxSettingsRepository;
use PHPUnit\Framework\TestCase;

final class TaxCalculatorTest extends TestCase
{
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

        $service = new TaxGroupService($groups, $taxSettings, $geography, new RecordingDispatcher());
        $this->standard = $service->create(new SaveTaxGroup('Standard'))->id;
        $this->reduced = $service->create(new SaveTaxGroup('Reduced'))->id;
        $service->addRate($this->standard, new SaveTaxRate('BG', null, '20', 'VAT'));
        $service->addRate($this->standard, new SaveTaxRate('DE', null, '19', 'MwSt'));
        $service->addRate($this->reduced, new SaveTaxRate('BG', null, '9', 'VAT'));

        $this->settings = new TaxSettingsService($taxSettings, $groups);
        $this->calculator = new TaxCalculator($groups, $taxSettings, new SettingsService($shop, new RecordingDispatcher(), $geography), $geography);
    }

    public function testItemsWithoutAGroupUseTheDefaultGroupOrAreNotTaxed(): void
    {
        $germany = new TaxQuery(shipping: new TaxAddress('DE'));

        self::assertEquals(new AppliedTaxesView('0', []), $this->calculator->taxes($germany));

        $this->settings->update(new UpdateTaxSettings('shipping_address', $this->standard));
        self::assertEquals(new AppliedTaxesView('19', [new AppliedTaxView(2, 'MwSt', '19')]), $this->calculator->taxes($germany));
        self::assertSame('0', $this->calculator->taxes(new TaxQuery($this->reduced, new TaxAddress('DE')))->total);
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
