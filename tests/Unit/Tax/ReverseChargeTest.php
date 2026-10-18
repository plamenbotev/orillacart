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

    public function testAGreekNumberUsesTheElPrefix(): void
    {
        self::assertTrue($this->shop->checkout->summary($this->owner, new CheckoutDetails(null, null, 'GR', vatNumber: 'EL094014201'))->reverseCharge);
        self::assertTrue($this->shop->checkout->summary($this->owner, new CheckoutDetails(null, null, 'GR', vatNumber: '094014201'))->reverseCharge);
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

    public function testTheOrderAndItsInvoiceSayTheCustomerAccountsForTheVat(): void
    {
        $placed = $this->shop->checkout->placeOrder($this->owner, new PlaceOrder('buyer@muster.de', new AddressData('Max', 'Muster', 'Hauptstr. 1', 'Berlin', '10115', 'DE', '+49 30 000000'), payment: 'bank_transfer', vatNumber: 'DE123456789'));

        $order = $this->shop->orders->byKey($placed->key);
        self::assertSame(['DE123456789', true, '10', '0'], [$order->vatNumber, $order->reverseCharge, $order->total, $order->itemsTax]);
        $this->shop->orders->markPaidByShop($order->number, '', 7);
        $html = $this->shop->templates->render('invoices/document.html.twig', ['document' => $this->shop->invoices->document($this->shop->invoices->ofOrder($order->number)[0]->number, $order->key), 'locale' => 'en-US']);
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
