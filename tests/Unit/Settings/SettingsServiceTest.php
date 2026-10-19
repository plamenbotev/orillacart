<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Settings;

use OrillaCart\Geography\Application\GeographyService;
use OrillaCart\Settings\Application\Dto\SettingsView;
use OrillaCart\Settings\Application\Dto\UpdateSettings;
use OrillaCart\Settings\Application\SettingsService;
use OrillaCart\Settings\Domain\SettingsChanged;
use OrillaCart\Settings\Domain\SettingsRepository;
use OrillaCart\Settings\Domain\StoreSettings;
use OrillaCart\Shared\Domain\RuleViolation;
use OrillaCart\Tests\Fixtures\Geography\InMemoryCountryRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;

final class SettingsServiceTest extends TestCase
{
    /** The values of the settings a shop leaves alone. */
    private const array UNSAID = ['shop_postcode' => null, 'currency_sign' => '', 'currency_position' => 'after_space', 'catalog_only' => false, 'hide_prices' => false, 'sell_to' => [], 'show_lowest_price' => false];

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
            new SettingsView('EUR', 2, 'kg', 'cm', null, null, ['kg', 'g', 'lb', 'oz'], ['cm', 'm', 'mm', 'in'], currencyPositions: ['before', 'before_space', 'after', 'after_space']),
            $this->service()->current(),
        );
    }

    public function testUpdatingSavesAndAnnouncesTheChange(): void
    {
        $view = $this->service()->update(new UpdateSettings('USD', 0, 'lb', 'in', 'US', 'NY'));

        self::assertSame('USD', $view->currency);
        self::assertSame(0, $this->repository->load()->priceDecimals);
        self::assertCount(1, $this->dispatcher->events);
        $event = $this->dispatcher->events[0];
        self::assertInstanceOf(SettingsChanged::class, $event);
        self::assertSame('settings_changed', $event->hookName());
        self::assertSame(
            [
                ['currency' => 'USD', 'price_decimals' => 0, 'weight_unit' => 'lb', 'dimension_unit' => 'in', 'shop_country' => 'US', 'shop_state' => 'NY'] + self::UNSAID,
                ['currency' => 'EUR', 'price_decimals' => 2, 'weight_unit' => 'kg', 'dimension_unit' => 'cm', 'shop_country' => null, 'shop_state' => null] + self::UNSAID,
            ],
            $event->hookArguments(),
        );
    }

    public function testSavingTheSameValuesAnnouncesNothing(): void
    {
        $this->service()->update(new UpdateSettings('EUR', 2, 'kg', 'cm', null, null));

        self::assertSame([], $this->dispatcher->events);
    }

    public function testAmountsHaveTheCurrencysDecimalsOrFewer(): void
    {
        $decimals = fn(string $currency, ?int $fewer = null): array => [($view = $this->service()->update(new UpdateSettings($currency, $fewer, 'kg', 'cm', null, null)))->priceDecimals, $view->fewerDecimals, $view->currencyDecimals];

        self::assertSame([0, null, 0], $decimals('JPY'));
        self::assertSame([3, null, 3], $decimals('KWD'));
        self::assertSame([2, null, 2], $decimals('HUF', 2), 'the currency\'s own is no choice of fewer');
        self::assertSame([0, 0, 2], $decimals('HUF', 0));
        self::assertSame([0, null, 0], $decimals('JPY', 0));

        try {
            $decimals('JPY', 2);
            self::fail('Yen have no decimals.');
        } catch (RuleViolation $e) {
            self::assertSame(['orillacart_invalid_price_decimals', 'Amounts in JPY have at most 0 decimals.'], [$e->errorCode, $e->getMessage()]);
        }
    }

    /**
     * @return iterable<string, array{string, ?string, string}>
     */
    public static function badShopAddresses(): iterable
    {
        yield 'unknown country' => ['XX', null, 'orillacart_unknown_country'];
        yield 'state of another country' => ['US', 'ON', 'orillacart_unknown_state'];
        yield 'state in a country without states' => ['BG', 'CA', 'orillacart_unknown_state'];
    }

    #[DataProvider('badShopAddresses')]
    public function testTheShopAddressMustExist(string $country, ?string $state, string $errorCode): void
    {
        try {
            $this->service()->update(new UpdateSettings('EUR', 2, 'kg', 'cm', $country, $state));
            self::fail('The address was accepted.');
        } catch (RuleViolation $e) {
            self::assertSame($errorCode, $e->errorCode);
        }
        self::assertNull($this->repository->load()->shopCountry);
    }

    public function testAStateNeedsACountry(): void
    {
        $this->expectException(RuleViolation::class);

        $this->service()->update(new UpdateSettings('EUR', 2, 'kg', 'cm', null, 'CA'));
    }

    public function testHowPricesShowAndWhereTheShopSells(): void
    {
        $view = $this->service()->update(new UpdateSettings('USD', 2, 'lb', 'in', 'US', 'NY', ' 10001 ', '$', 'before', catalogOnly: true, hidePrices: true, sellTo: ['US', 'CA', 'US']));

        self::assertSame(['10001', '$', 'before', true, true, ['US', 'CA']], [$view->shopPostcode, $view->currencySign, $view->currencyPosition, $view->catalogOnly, $view->hidePrices, $view->sellTo]);
        self::assertTrue($this->repository->load()->sellsTo('CA'));
        self::assertFalse($this->repository->load()->sellsTo('BG'));

        foreach ([
            [new UpdateSettings('USD', 2, 'lb', 'in', null, null, sellTo: ['XX']), 'orillacart_unknown_country'],
            [new UpdateSettings('USD', 2, 'lb', 'in', null, null, currencySign: 'dollars!!'), 'orillacart_invalid_currency_sign'],
        ] as [$command, $code]) {
            try {
                $this->service()->update($command);
                self::fail('Refused: ' . $code);
            } catch (RuleViolation $e) {
                self::assertSame($code, $e->errorCode);
            }
        }
    }

    private function service(): SettingsService
    {
        return new SettingsService($this->repository, $this->dispatcher, new GeographyService(new InMemoryCountryRepository()));
    }
}
