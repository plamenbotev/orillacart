<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Tax;

use OrillaCart\Cart\Application\Dto\AddToCart;
use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Checkout\Application\Dto\CheckoutDetails;
use OrillaCart\Checkout\Application\Dto\PlaceOrder;
use OrillaCart\Ordering\Application\Dto\AddressData;
use OrillaCart\Payment\Application\Dto\SavePaymentMethod;
use OrillaCart\Shared\Domain\Money;
use OrillaCart\Shipping\Application\Dto\SaveCarrier;
use OrillaCart\Shipping\Application\Dto\SaveShippingRate;
use OrillaCart\Tax\Application\Dto\SaveTaxGroup;
use OrillaCart\Tax\Application\Dto\SaveTaxRate;
use OrillaCart\Tax\Domain\TaxBasis;
use OrillaCart\Tax\Domain\TaxSettings;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use PHPUnit\Framework\TestCase;

/**
 * A business in another EU country than the shop's, with a VAT number VIES confirms, buys without VAT.
 */
final class ReverseChargeTest extends TestCase
{
    private TestShop $shop;
    private CartOwner $owner;
    private int $vat;

    protected function setUp(): void
    {
        $this->shop = new TestShop();
        $this->vat = $this->shop->taxGroups->create(new SaveTaxGroup('Standard'))->id;
        foreach (['BG' => '20', 'DE' => '19', 'GR' => '24'] as $country => $percent) {
            $this->shop->taxGroups->addRate($this->vat, new SaveTaxRate($country, null, $percent, 'VAT'));
        }
        $this->shop->taxSettings->save(new TaxSettings(TaxBasis::BillingAddress, $this->vat, false, true));
        $this->shop->content->add(20, 'E-book');
        $this->shop->products->save(20, new SaveProduct('10', 'virtual'));
        $this->shop->paymentMethods->update('bank_transfer', new SavePaymentMethod('Bank transfer', true));
        $this->shop->vies->known = ['DE123456789' => 'Muster GmbH', 'EL094014201' => 'Greek SA'];
        $this->owner = new CartOwner($this->shop->carts->add(new CartOwner(null), new AddToCart(20))->newToken);
    }

    public function testABusinessInAnotherEuCountryBuysWithoutVat(): void
    {
        $summary = $this->shop->checkout->summary($this->owner, new CheckoutDetails(null, null, 'DE', vatNumber: 'de 123 456 789'));

        self::assertSame([true, true, null, '10', []], [$summary->vatAsked, $summary->reverseCharge, $summary->vatMessage, $summary->total, $summary->taxes]);
    }

    public function testGoodsDeliveredInTheShopsCountryPayVatWhateverTheNumber(): void
    {
        $this->shop->content->add(10, 'Lamp');
        $this->shop->products->save(10, new SaveProduct('30', weight: '1'));
        $rate = $this->shop->carriers->create(new SaveCarrier('Courier', rates: [new SaveShippingRate('EU', '5', regions: ['BG', 'DE'])]))->rates[0]->id;
        $owner = new CartOwner($this->shop->carts->add(new CartOwner(null), new AddToCart(10))->newToken);

        $home = $this->shop->checkout->summary($owner, new CheckoutDetails('BG', null, 'DE', shippingOption: "rate:{$rate}", vatNumber: 'DE123456789'));
        self::assertFalse($home->reverseCharge, 'a German business taking the lamp in Sofia: a sale in Bulgaria');
        self::assertSame('The goods are delivered in the shop\'s own country, so VAT is charged, even with a VAT number of another EU country.', $home->vatMessage);

        self::assertTrue($this->shop->checkout->summary($owner, new CheckoutDetails('DE', null, 'DE', shippingOption: "rate:{$rate}", vatNumber: 'DE123456789'))->reverseCharge, 'sent to Germany');
    }

    public function testGoodsAtHomePayVatAndAnEbookInTheSameOrderStaysUnderTheReverseCharge(): void
    {
        $this->shop->content->add(10, 'Lamp');
        $this->shop->products->save(10, new SaveProduct('30', weight: '1'));
        $rate = $this->shop->carriers->create(new SaveCarrier('Courier', rates: [new SaveShippingRate('EU', '5', regions: ['BG', 'DE'])]))->rates[0]->id;
        $owner = new CartOwner($this->shop->carts->add($this->owner, new AddToCart(10))->newToken ?? $this->owner->token);

        $mixed = $this->shop->checkout->summary($owner, new CheckoutDetails('BG', null, 'DE', shippingOption: "rate:{$rate}", vatNumber: 'DE123456789'));

        self::assertTrue($mixed->reverseCharge, 'the e-book is a service to a German business');
        $taxOf = static fn(string $name): string => (string) Money::of(array_values(array_filter($mixed->lines, static fn($line): bool => $line->name === $name))[0]->tax ?? '0');
        self::assertSame(['0', '5.7'], [$taxOf('E-book'), $taxOf('Lamp')], 'VAT on the lamp alone, at the rate the shop\'s settings take');
    }

    public function testAGreekNumberUsesTheElPrefix(): void
    {
        self::assertTrue($this->shop->checkout->summary($this->owner, new CheckoutDetails(null, null, 'GR', vatNumber: 'EL094014201'))->reverseCharge);
        self::assertTrue($this->shop->checkout->summary($this->owner, new CheckoutDetails(null, null, 'GR', vatNumber: '094014201'))->reverseCharge);
        self::assertTrue($this->shop->checkout->summary($this->owner, new CheckoutDetails(null, null, 'GR', vatNumber: 'GR094014201'))->reverseCharge, 'written with the ISO code');
        self::assertSame('EL094014201', end($this->shop->vies->asked));
    }

    public function testGoodsForNorthernIrelandAreSoldAsInTheEu(): void
    {
        $this->shop->taxGroups->addRate($this->vat, new SaveTaxRate('GB', null, '20', 'UK VAT'));
        $this->shop->taxSettings->save(new TaxSettings(TaxBasis::EuDestination, $this->vat, false, true));
        $this->shop->vies->known['XI123456789'] = 'Belfast Ltd';
        $this->shop->content->add(10, 'Lamp');
        $this->shop->products->save(10, new SaveProduct('30', weight: '1'));
        $rate = $this->shop->carriers->create(new SaveCarrier('Courier', rates: [new SaveShippingRate('UK', '5', taxable: false, regions: ['GB'])]))->rates[0]->id;
        $owner = new CartOwner($this->shop->carts->add(new CartOwner(null), new AddToCart(10))->newToken);
        $to = static fn(string $postcode, ?string $number = null): CheckoutDetails => new CheckoutDetails('GB', null, 'GB', null, "rate:{$rate}", $postcode, vatNumber: $number);

        $consumer = $this->shop->checkout->summary($owner, $to('BT1 1AA'));
        self::assertSame([['UK VAT', '6']], array_map(static fn($tax): array => [$tax->name, $tax->total], $consumer->taxes), 'Belfast: the destination\'s VAT, as in the EU');
        self::assertSame([], $this->shop->checkout->summary($owner, $to('SW1A 1AA'))->taxes, 'London: an export');

        $business = $this->shop->checkout->summary($owner, $to('BT1 1AA', 'XI 123 456 789'));
        self::assertSame([true, []], [$business->reverseCharge, $business->taxes], 'an XI number VIES confirms');
        self::assertSame('XI123456789', end($this->shop->vies->asked));
        self::assertFalse($this->shop->checkout->summary($owner, $to('SW1A 1AA', 'XI123456789'))->reverseCharge, 'goods for Great Britain leave the EU');
    }

    public function testANumberVatIsNotSureOfPaysVatAndSaysWhy(): void
    {
        $unknown = $this->shop->checkout->summary($this->owner, new CheckoutDetails(null, null, 'DE', vatNumber: 'DE999999999'));
        self::assertSame([false, '11.9'], [$unknown->reverseCharge, $unknown->total]);
        self::assertSame('The EU does not know this VAT number, so VAT is charged.', $unknown->vatMessage);

        $this->shop->vies->answers = false;
        $down = $this->shop->checkout->summary($this->owner, new CheckoutDetails(null, null, 'DE', vatNumber: 'DE123456789'));
        self::assertSame([false, '11.9'], [$down->reverseCharge, $down->total], 'never zero-rated on trust');
        self::assertStringStartsWith('Your VAT number could not be checked right now', (string) $down->vatMessage);
    }

    public function testABusinessInTheShopsOwnCountryPaysVat(): void
    {
        $summary = $this->shop->checkout->summary($this->owner, new CheckoutDetails(null, null, 'BG', vatNumber: 'BG123456789'));

        self::assertSame([false, null, '12'], [$summary->reverseCharge, $summary->vatMessage, $summary->total]);
        self::assertSame([], $this->shop->vies->asked, 'VIES is not asked');
    }

    public function testABusinessOutsideTheEuKeepsANumberWhoseCheckDigitHolds(): void
    {
        $summary = $this->shop->checkout->summary($this->owner, new CheckoutDetails(null, null, 'GB', vatNumber: '980 7806 84'));
        self::assertSame([false, null], [$summary->reverseCharge, $summary->vatMessage]);

        $wrong = $this->shop->checkout->summary($this->owner, new CheckoutDetails(null, null, 'GB', vatNumber: 'GB980780685'));
        self::assertSame('This is not a valid VAT number, so it is not on the order. Check it and try again, or leave it out.', $wrong->vatMessage);
        self::assertSame([], $this->shop->vies->asked, 'VIES is not asked');

        $placed = $this->shop->checkout->placeOrder($this->owner, new PlaceOrder('buyer@example.ca', new AddressData('Jo', 'Tremblay', '1 Rue Sainte-Catherine', 'Montreal', 'H2X 1Y4', 'CA', '+1 514 000 0000', 'QC'), payment: 'bank_transfer', vatNumber: '123456782 RT0001'));
        $order = $this->shop->orders->order($placed->number);
        self::assertSame('123456782RT0001', $order->vatNumber);
        $this->shop->orders->markPaidByShop($order->number, '', 7);
        $html = $this->shop->templates->render('invoices/document.html.twig', ['document' => $this->shop->invoices->document($this->shop->invoices->ofOrder($order->number)[0]->number, $this->shop->receipts->issue($order->number)), 'locale' => 'en-US']);
        self::assertStringContainsString('Business number: 123456782RT0001', $html);

        $dropped = $this->shop->checkout->placeOrder(new CartOwner($this->shop->carts->add(new CartOwner(null), new AddToCart(20))->newToken), new PlaceOrder('buyer@example.ca', new AddressData('Jo', 'Tremblay', '1 Rue Sainte-Catherine', 'Montreal', 'H2X 1Y4', 'CA', '+1 514 000 0000', 'QC'), payment: 'bank_transfer', vatNumber: '123456789'));
        self::assertNull($this->shop->orders->order($dropped->number)->vatNumber, 'a number whose check digit fails is not kept');
    }

    public function testTheOrderAndItsInvoiceSayTheCustomerAccountsForTheVat(): void
    {
        $placed = $this->shop->checkout->placeOrder($this->owner, new PlaceOrder('buyer@muster.de', new AddressData('Max', 'Muster', 'Hauptstr. 1', 'Berlin', '10115', 'DE', '+49 30 000000'), payment: 'bank_transfer', vatNumber: 'DE123456789'));

        $order = $this->shop->orders->order($placed->number);
        self::assertSame(['DE123456789', true, '10', '0'], [$order->vatNumber, $order->reverseCharge, $order->total, $order->itemsTax]);
        $this->shop->orders->markPaidByShop($order->number, '', 7);
        $html = $this->shop->templates->render('invoices/document.html.twig', ['document' => $this->shop->invoices->document($this->shop->invoices->ofOrder($order->number)[0]->number, $this->shop->receipts->issue($order->number)), 'locale' => 'en-US']);
        self::assertStringContainsString('DE123456789', $html);
        self::assertStringContainsString('Reverse charge', $html);
    }

    public function testWithPricesIncludingTaxTheBusinessPaysThemLessTheShopsVat(): void
    {
        $this->shop->taxSettings->save(new TaxSettings(TaxBasis::BillingAddress, $this->vat, true, true));

        $summary = $this->shop->checkout->summary($this->owner, new CheckoutDetails(null, null, 'DE', vatNumber: 'DE123456789'));

        // 10.00 includes the shop's 20 %: 8.33 before it.
        self::assertSame(['8.33', []], [$summary->total, $summary->taxes]);
    }

    public function testWithoutTheSettingNoNumberIsAskedOrChecked(): void
    {
        $this->shop->taxSettings->save(new TaxSettings(TaxBasis::BillingAddress, $this->vat));

        $summary = $this->shop->checkout->summary($this->owner, new CheckoutDetails(null, null, 'DE', vatNumber: 'DE123456789'));

        self::assertSame([false, false, '11.9'], [$summary->vatAsked, $summary->reverseCharge, $summary->total]);
    }
}
