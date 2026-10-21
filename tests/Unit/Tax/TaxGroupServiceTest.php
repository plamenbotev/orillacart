<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Tax;

use OrillaCart\Geography\Application\GeographyService;
use OrillaCart\Geography\Domain\UnknownState;
use OrillaCart\Shared\Domain\RuleViolation;
use OrillaCart\Tax\Application\Dto\SaveTaxGroup;
use OrillaCart\Tax\Application\Dto\SaveTaxRate;
use OrillaCart\Tax\Application\Dto\TaxRateView;
use OrillaCart\Tax\Application\TaxGroupService;
use OrillaCart\Tax\Domain\Event\TaxRatesChanged;
use OrillaCart\Tax\Domain\TaxBasis;
use OrillaCart\Tax\Domain\TaxGroupNotFound;
use OrillaCart\Tax\Domain\TaxRateNotFound;
use OrillaCart\Tax\Domain\TaxSettings;
use OrillaCart\Tests\Fixtures\Geography\InMemoryCountryRepository;
use OrillaCart\Tests\Fixtures\RecordingDispatcher;
use OrillaCart\Tests\Fixtures\Tax\InMemoryTaxGroupRepository;
use OrillaCart\Tests\Fixtures\Tax\InMemoryTaxSettingsRepository;
use PHPUnit\Framework\TestCase;

final class TaxGroupServiceTest extends TestCase
{
    private InMemoryTaxGroupRepository $groups;
    private InMemoryTaxSettingsRepository $settings;
    private RecordingDispatcher $events;
    private TaxGroupService $service;

    protected function setUp(): void
    {
        $this->groups = new InMemoryTaxGroupRepository();
        $this->settings = new InMemoryTaxSettingsRepository();
        $this->events = new RecordingDispatcher();
        $this->service = new TaxGroupService($this->groups, $this->settings, new GeographyService(new InMemoryCountryRepository()), $this->events);
    }

    public function testGroupsAreCreatedRenamedAndListedByName(): void
    {
        $standard = $this->service->create(new SaveTaxGroup('Standard'));
        $this->service->create(new SaveTaxGroup('Reduced'));
        $this->service->rename($standard->id, new SaveTaxGroup('Basic'));

        self::assertSame(['Basic', 'Reduced'], array_column($this->service->groups(), 'name'));
        self::assertSame([], $this->events->events, 'groups without rates change no price');
    }

    public function testRatesAreAddedChangedAndRemovedWithAnAnnouncement(): void
    {
        $group = $this->service->create(new SaveTaxGroup('Standard'));

        $rate = $this->service->addRate($group->id, new SaveTaxRate('US', 'CA', '7.25', ' California ', 1));
        self::assertEquals(new TaxRateView($rate->id, 'US', 'CA', '7.25', 'California', 1, [], false), $rate);

        $changed = $this->service->changeRate($group->id, $rate->id, new SaveTaxRate('US', null, '2', 'Federal'));
        self::assertEquals(new TaxRateView($rate->id, 'US', null, '2', 'Federal', 0, [], false), $changed);
        self::assertEquals([$changed], $this->service->group($group->id)->rates);

        $local = $this->service->changeRate($group->id, $rate->id, new SaveTaxRate('US', 'CA', '1.25', 'Local', 1, [' 900* ', '', '91000-91999'], true));
        self::assertEquals(new TaxRateView($rate->id, 'US', 'CA', '1.25', 'Local', 1, ['900*', '91000-91999'], true), $local);

        $this->service->removeRate($group->id, $rate->id);
        self::assertSame([], $this->service->group($group->id)->rates);

        self::assertEquals(array_fill(0, 4, new TaxRatesChanged($group->id)), $this->events->events);
    }

    public function testRatesNeedAKnownLocation(): void
    {
        $group = $this->service->create(new SaveTaxGroup('Standard'));

        $this->expectException(UnknownState::class);
        $this->service->addRate($group->id, new SaveTaxRate('DE', 'BY', '19'));
    }

    public function testRatesOfAnotherGroupCannotBeChanged(): void
    {
        $standard = $this->service->create(new SaveTaxGroup('Standard'));
        $reduced = $this->service->create(new SaveTaxGroup('Reduced'));
        $rate = $this->service->addRate($standard->id, new SaveTaxRate('DE', null, '19'));

        $this->expectException(TaxRateNotFound::class);
        $this->service->changeRate($reduced->id, $rate->id, new SaveTaxRate('DE', null, '7'));
    }

    public function testTheDefaultGroupCannotBeDeleted(): void
    {
        $standard = $this->service->create(new SaveTaxGroup('Standard'));
        $reduced = $this->service->create(new SaveTaxGroup('Reduced'));
        $this->settings->save(new TaxSettings(TaxBasis::ShippingAddress, $standard->id));

        $this->service->delete($reduced->id);
        self::assertSame(['Standard'], array_column($this->service->groups(), 'name'));

        try {
            $this->service->delete($standard->id);
            self::fail('The default group was deleted.');
        } catch (RuleViolation $e) {
            self::assertSame('orillacart_tax_group_in_use', $e->errorCode);
        }

        $this->expectException(TaxGroupNotFound::class);
        $this->service->group($reduced->id);
    }
}
