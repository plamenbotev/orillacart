<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Settings;

use OrillaCart\Settings\Application\Dto\SettingsView;
use OrillaCart\Settings\Application\Dto\UpdateSettings;
use OrillaCart\Settings\Application\SettingsService;
use OrillaCart\Settings\Domain\SettingsChanged;
use OrillaCart\Settings\Domain\SettingsRepository;
use OrillaCart\Settings\Domain\StoreSettings;
use OrillaCart\Shared\Domain\RuleViolation;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;

final class SettingsServiceTest extends TestCase
{
    private SettingsRepository $repository;

    /** @var object{events: list<object>}&EventDispatcherInterface */
    private EventDispatcherInterface $dispatcher;

    protected function setUp(): void
    {
        $this->dispatcher = new class implements EventDispatcherInterface {
            /** @var list<object> */
            public array $events = [];

            public function dispatch(object $event): object
            {
                $this->events[] = $event;

                return $event;
            }
        };
        $this->repository = new class implements SettingsRepository {
            public ?StoreSettings $saved = null;

            public function load(): StoreSettings
            {
                return $this->saved ?? StoreSettings::defaults();
            }

            public function save(StoreSettings $settings): void
            {
                $this->saved = $settings;
            }
        };
    }

    public function testAShopThatNeverSavedRunsOnTheDefaults(): void
    {
        self::assertEquals(
            new SettingsView('EUR', 2, 'kg', 'cm', ['kg', 'g', 'lb', 'oz'], ['cm', 'm', 'mm', 'in']),
            $this->service()->current(),
        );
    }

    public function testUpdatingSavesAndAnnouncesTheChange(): void
    {
        $view = $this->service()->update(new UpdateSettings('USD', 3, 'lb', 'in'));

        self::assertSame('USD', $view->currency);
        self::assertSame(3, $this->repository->load()->priceDecimals);
        self::assertCount(1, $this->dispatcher->events);
        $event = $this->dispatcher->events[0];
        self::assertInstanceOf(SettingsChanged::class, $event);
        self::assertSame('settings_changed', $event->hookName());
        self::assertSame(
            [
                ['currency' => 'USD', 'price_decimals' => 3, 'weight_unit' => 'lb', 'dimension_unit' => 'in'],
                ['currency' => 'EUR', 'price_decimals' => 2, 'weight_unit' => 'kg', 'dimension_unit' => 'cm'],
            ],
            $event->hookArguments(),
        );
    }

    public function testSavingTheSameValuesAnnouncesNothing(): void
    {
        $this->service()->update(new UpdateSettings('EUR', 2, 'kg', 'cm'));

        self::assertSame([], $this->dispatcher->events);
    }

    public function testTheDomainRefusesWhatValidationMissed(): void
    {
        $this->expectException(RuleViolation::class);

        $this->service()->update(new UpdateSettings('EUR', 7, 'kg', 'cm'));
    }

    private function service(): SettingsService
    {
        return new SettingsService($this->repository, $this->dispatcher);
    }
}
