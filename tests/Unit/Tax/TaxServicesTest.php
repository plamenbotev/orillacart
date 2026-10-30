<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Tax;

use OrillaCart\Cart\Application\Dto\AddToCart;
use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Checkout\Application\Dto\CheckoutDetails;
use OrillaCart\Checkout\Application\Dto\PlaceOrder;
use OrillaCart\Extension\ProviderUnavailable;
use OrillaCart\Extension\Tax\TaxedOrder;
use OrillaCart\Extension\Tax\TaxLine;
use OrillaCart\Extension\Tax\TaxProvider;
use OrillaCart\Extension\Tax\TaxRefund;
use OrillaCart\Extension\Tax\TaxRequest;
use OrillaCart\Extension\Tax\TaxResult;
use OrillaCart\Jobs\Application\Dto\JobOutcome;
use OrillaCart\Jobs\Application\Dto\QueuedJob;
use OrillaCart\Ordering\Application\Dto\AddressData;
use OrillaCart\Payment\Application\Dto\SavePaymentMethod;
use OrillaCart\Shared\Domain\RuleViolation;
use OrillaCart\Shipping\Application\Dto\SaveCarrier;
use OrillaCart\Shipping\Application\Dto\SaveShippingRate;
use OrillaCart\Tax\Application\Dto\SaveTaxGroup;
use OrillaCart\Tax\Application\Dto\SaveTaxRate;
use OrillaCart\Tax\Application\Dto\UpdateTaxSettings;
use OrillaCart\Tax\Application\RecordTaxes;
use OrillaCart\Tax\Application\TaxServices;
use OrillaCart\Tax\Application\TaxServiceUnavailable;
use OrillaCart\Tax\Application\TaxSettingsService;
use OrillaCart\Tax\Domain\TaxBasis;
use OrillaCart\Tax\Domain\TaxSettings;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use OrillaCart\Tests\Fixtures\Jobs\RecordedProgress;
use OrillaCart\Tests\Fixtures\Tax\InMemoryTaxRefundReports;
use PHPUnit\Framework\TestCase;

/**
 * A tax service the shop chose works out the taxes line by line in place of the shop's rates, and is told what
 * became of the order: the sale once paid, each refund, a cancellation.
 */
final class TaxServicesTest extends TestCase
{
    private TestShop $shop;
    private FakeTaxService $service;
    private int $rate;
    private int $group;

    protected function setUp(): void
    {
        $this->shop = new TestShop();
        $this->group = $this->shop->taxGroups->create(new SaveTaxGroup('Standard'))->id;
        $this->shop->taxGroups->addRate($this->group, new SaveTaxRate('US', 'CA', '6', 'California'));
        $this->shop->content->add(10, 'Lamp');
        $this->shop->products->save(10, new SaveProduct('100', weight: '1', stockMode: 'always_available'));
        $this->rate = $this->shop->carriers->create(new SaveCarrier('Courier', rates: [new SaveShippingRate('US', '10', regions: ['US'])]))->rates[0]->id;
        $this->shop->paymentMethods->update('bank_transfer', new SavePaymentMethod('Bank transfer', true));
        $this->service = new FakeTaxService();
        $this->shop->extensions->taxProviders = [$this->service];
        $this->shop->taxSettings->save(new TaxSettings(TaxBasis::ShippingAddress, $this->group, provider: 'fake_tax'));
    }

    public function testTheServicesTaxesStandInForTheShopsRatesLineByLine(): void
    {
        $order = $this->shop->orders->order($this->place());

        self::assertSame(['8', '0.6', '10', '118.6'], [$order->lines[0]->tax, $order->charges[0]->tax, $order->shippingTotal, $order->total], '6 % and 2 % on the lamp, 6 % on shipping');
        self::assertSame([['CA', '6', 'region'], ['LA-CITY', '2', 'local']], array_map(static fn($tax): array => [$tax->code, $tax->percent, $tax->level], $order->lines[0]->taxes));
        self::assertSame('fake_tax', $order->taxProvider);
        $asked = $this->service->asked[0];
        self::assertSame([['item:0', 'item', null, '100'], ['shipping', 'shipping', null, '10']], array_map(static fn($line): array => [$line->id, $line->kind, $line->taxCode, $line->amount], $asked->lines));
        self::assertSame(['US', 'CA', '90012', 'Los Angeles', '1 Main St'], [$asked->shipTo->country, $asked->shipTo->region, $asked->shipTo->postcode, $asked->shipTo->city, $asked->shipTo->street]);
    }

    public function testTheServiceHearsWhatItCallsEachLinesGoods(): void
    {
        $this->shop->taxGroups->rename($this->group, new SaveTaxGroup('Standard', 'P0000000'));
        $this->shop->content->add(11, 'Scarf');
        $this->shop->products->save(11, new SaveProduct('20', weight: '1', stockMode: 'always_available', taxCode: 'PC040100'));
        $owner = $this->cart();
        $this->shop->carts->add($owner, new AddToCart(11));
        $address = new AddressData('Ada', 'Lovelace', '1 Main St', 'Los Angeles', '90012', 'US', '+1 213 000 0000', 'CA');
        $this->shop->checkout->placeOrder($owner, new PlaceOrder('ada@example.com', $address, shippingOption: "rate:{$this->rate}", payment: 'bank_transfer'));

        $asked = array_pop($this->service->asked);
        self::assertNotNull($asked);
        self::assertSame(['item:0' => 'P0000000', 'item:1' => 'PC040100', 'shipping' => 'P0000000'], array_column(array_map(static fn($line): array => ['id' => $line->id, 'code' => $line->serviceCode], $asked->lines), 'code', 'id'), 'the group\'s, the scarf\'s own, and the default group\'s for shipping');

        $this->expectException(RuleViolation::class);
        $this->shop->taxGroups->rename($this->group, new SaveTaxGroup('Standard', 'not a code'));
    }

    public function testTheServicesAnswerIsKeptAFewMinutesForTheSameLines(): void
    {
        $this->place();
        $asked = \count($this->service->asked);
        $request = $this->service->asked[$asked - 1];
        $later = new TaxServices($this->shop->taxSettings, $this->shop->extensions, $this->shop->logger, $this->shop->failures, $this->shop->taxGroupRecords, $this->shop->taxQuotes);

        $this->service->down = true;
        self::assertSame('8', (string) array_sum(array_map(static fn(TaxLine $tax): float => (float) $tax->amount, $later->quote($request)?->lines['item:0'] ?? [])), 'from what was kept, as the service is down');
        self::assertCount($asked, $this->service->asked, 'not asked again');

        $this->shop->taxQuotes->rows = array_map(static fn(array $rows): array => [['line' => 'item:0', 'name' => 'Made up']], $this->shop->taxQuotes->rows);
        $this->expectException(TaxServiceUnavailable::class);
        $this->shop->taxSettings->save(new TaxSettings(TaxBasis::ShippingAddress, $this->group, provider: 'fake_tax', whenProviderFails: TaxSettings::REFUSE));
        new TaxServices($this->shop->taxSettings, $this->shop->extensions, $this->shop->logger, $this->shop->failures, $this->shop->taxGroupRecords, $this->shop->taxQuotes)->quote($request);
    }

    public function testWhenTheServiceFailsTheShopsRatesTaxTheOrderOrCheckoutRefusesAsTheShopChose(): void
    {
        $this->service->down = true;
        $order = $this->shop->orders->order($this->place());
        self::assertSame(['6', null], [$order->lines[0]->tax, $order->taxProvider], 'the shop\'s 6 %');
        self::assertContains('The tax service fake_tax failed; the order was taxed at the shop\'s own rates.', array_column($this->shop->orders->order($order->number)->notes, 'text'));

        $this->shop->taxSettings->save(new TaxSettings(TaxBasis::ShippingAddress, $this->group, provider: 'fake_tax', whenProviderFails: TaxSettings::REFUSE));
        $this->expectException(TaxServiceUnavailable::class);
        $this->shop->checkout->summary($this->cart(), new CheckoutDetails('US', 'CA', '90012'));
    }

    public function testTheServicesAmountsAreInTheCurrencysCents(): void
    {
        $this->service->cityTax = '2.0049';

        $order = $this->shop->orders->order($this->place());

        self::assertSame(['8', '118.6'], [$order->lines[0]->tax, $order->total], '2.0049 is charged as 2.00');
    }

    public function testWithNoRatesOfItsOwnTheShopRefusesRatherThanChargeNoTax(): void
    {
        $this->shop->taxGroups->removeRate($this->group, $this->shop->taxGroups->groups()[0]->rates[0]->id);
        $this->service->down = true;

        $this->expectException(TaxServiceUnavailable::class);
        $this->shop->checkout->summary($this->cart(), new CheckoutDetails('US', 'CA', '90012'));
    }

    public function testAServiceTakesPricesEnteredWithoutTax(): void
    {
        $this->expectExceptionMessage('A tax service works out the tax on prices entered without it');
        new TaxSettingsService($this->shop->taxSettings, $this->shop->taxGroupRecords, $this->shop->extensions)->update(new UpdateTaxSettings('shipping_address', $this->group, pricesIncludeTax: true, provider: 'fake_tax'));
    }

    public function testTheServiceIsToldOfTheSaleItsRefundsAndCancellations(): void
    {
        $number = $this->place();
        $reports = new InMemoryTaxRefundReports();
        $record = new RecordTaxes($this->shop->orders, $this->shop->taxServices, $this->shop->settingsService, $reports, $this->shop->clock);
        $job = fn(string $action, array $more = []): QueuedJob => self::job($number, $action, $more);
        $outcome = static function (QueuedJob $job) use ($record): string {
            $progress = new RecordedProgress();

            return self::outcome($progress->with($record->handle([$job], $progress->progress)));
        };

        self::assertSame('retry', $outcome($job(RecordTaxes::REFUND, ['amount' => '10', 'tax' => '0.8'])), 'a refund waits for the sale');
        $outcome($job(RecordTaxes::COMMIT));
        self::assertSame('TX-' . $number, $this->shop->orders->order($number)->taxReference);
        self::assertSame(['item:0' => '8', 'shipping' => '0.6'], array_map(static fn(array $taxes): string => (string) array_sum(array_map(static fn(TaxLine $tax): float => (float) $tax->amount, $taxes)), $this->service->committed[0]->taxes));

        $outcome($job(RecordTaxes::COMMIT));
        self::assertCount(1, $this->service->committed, 'a sale is recorded once');
        $refund = $job(RecordTaxes::REFUND, ['amount' => '10.6', 'tax' => '0.6', 'full' => false, 'reference' => "{$number}:ab12"]);
        $outcome($refund);
        self::assertSame('done', $outcome($refund), 'run again after its worker died: the service was told already');
        self::assertSame([['10.6', '0.6', false]], array_map(static fn(TaxRefund $refund): array => [$refund->amount, $refund->tax, $refund->full], $this->service->refunds));
        self::assertSame(["{$number}:ab12"], array_keys($reports->reports));
        $outcome($job(RecordTaxes::VOID));
        self::assertSame(['TX-' . $number], $this->service->voided);

        $this->service->down = true;
        self::assertSame('retry', $outcome($job(RecordTaxes::REFUND, ['amount' => '1', 'tax' => '0'])), 'tried again later');
    }

    /**
     * @param array<array-key, mixed> $more
     */
    private static function job(int $number, string $action, array $more): QueuedJob
    {
        $payload = ['order' => $number, 'action' => $action];
        foreach ($more as $key => $value) {
            $payload[(string) $key] = $value;
        }

        return new QueuedJob('1', 'tax', RecordTaxes::TYPE, null, $payload, 1, 1);
    }

    /**
     * @param array<string, JobOutcome> $outcomes
     */
    private static function outcome(array $outcomes): string
    {
        foreach ($outcomes as $outcome) {
            return $outcome->kind;
        }

        return 'done';
    }

    private function place(): int
    {
        $address = new AddressData('Ada', 'Lovelace', '1 Main St', 'Los Angeles', '90012', 'US', '+1 213 000 0000', 'CA');

        return $this->shop->checkout->placeOrder($this->cart(), new PlaceOrder('ada@example.com', $address, shippingOption: "rate:{$this->rate}", payment: 'bank_transfer'))->number;
    }

    private function cart(): CartOwner
    {
        return new CartOwner($this->shop->carts->add(new CartOwner(null), new AddToCart(10))->newToken);
    }
}

/**
 * A tax service charging 6 % for the state and 2 % for the city on items, and the state's only on shipping.
 */
final class FakeTaxService implements TaxProvider
{
    public bool $down = false;
    /** The city's tax as it answers it, unrounded: four decimals the service may give. */
    public ?string $cityTax = null;
    /** @var list<TaxRequest> */
    public array $asked = [];
    /** @var list<TaxedOrder> */
    public array $committed = [];
    /** @var list<TaxRefund> */
    public array $refunds = [];
    /** @var list<?string> */
    public array $voided = [];

    public function id(): string
    {
        return 'fake_tax';
    }

    public function label(): string
    {
        return 'Fake tax';
    }

    public function quote(TaxRequest $request): TaxResult
    {
        $this->up();
        $this->asked[] = $request;
        $lines = [];
        foreach ($request->lines as $line) {
            $amount = (float) $line->amount;
            $lines[$line->id] = [new TaxLine('California', '6', 'US', 'CA', TaxLine::REGION, 'CA', 'California', $line->amount, number_format($amount * 0.06, 2, '.', ''))];
            if ($line->kind === 'item') {
                $lines[$line->id][] = new TaxLine('Los Angeles', '2', 'US', 'CA', TaxLine::LOCAL, 'LA-CITY', 'Los Angeles', $line->amount, $this->cityTax ?? number_format($amount * 0.02, 2, '.', ''));
            }
        }

        return new TaxResult($lines);
    }

    public function commit(TaxedOrder $order): string
    {
        $this->up();
        $this->committed[] = $order;

        return 'TX-' . $order->number;
    }

    public function refund(TaxedOrder $order, TaxRefund $refund): void
    {
        $this->up();
        $this->refunds[] = $refund;
    }

    public function void(TaxedOrder $order): void
    {
        $this->up();
        $this->voided[] = $order->reference;
    }

    private function up(): void
    {
        if ($this->down) {
            throw new ProviderUnavailable('Fake tax', 'The service is down.');
        }
    }
}
