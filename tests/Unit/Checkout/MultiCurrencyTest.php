<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Checkout;

use OrillaCart\Cart\Application\Dto\AddToCart;
use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Checkout\Application\Dto\CheckoutDetails;
use OrillaCart\Checkout\Application\Dto\CouponCode;
use OrillaCart\Checkout\Application\Dto\PlaceOrder;
use OrillaCart\Coupons\Application\Dto\SaveCoupon;
use OrillaCart\Ordering\Application\Dto\AddressData;
use OrillaCart\Payment\Application\Dto\SavePaymentMethod;
use OrillaCart\Settings\Application\Dto\CurrencySettingsView;
use OrillaCart\Settings\Application\Dto\OfferedCurrencyView;
use OrillaCart\Shared\Domain\RuleViolation;
use OrillaCart\Shipping\Application\Dto\SaveCarrier;
use OrillaCart\Shipping\Application\Dto\SaveRange;
use OrillaCart\Shipping\Application\Dto\SaveShippingRate;
use OrillaCart\Tax\Application\Dto\SaveTaxGroup;
use OrillaCart\Tax\Application\Dto\SaveTaxRate;
use OrillaCart\Tax\Domain\TaxBasis;
use OrillaCart\Tax\Domain\TaxSettings;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use PHPUnit\Framework\TestCase;

final class MultiCurrencyTest extends TestCase
{
    private TestShop $shop;
    private int $rate;

    protected function setUp(): void
    {
        $this->shop = new TestShop();
        $vat = $this->shop->taxGroups->create(new SaveTaxGroup('Standard'))->id;
        $this->shop->taxGroups->addRate($vat, new SaveTaxRate('BG', null, '20', 'VAT'));
        $this->shop->taxSettings->save(new TaxSettings(TaxBasis::ShippingAddress, $vat));
        $this->shop->content->add(10, 'Lamp');
        $this->shop->products->save(10, new SaveProduct('33.33', weight: '1'));
        $this->rate = $this->shop->carriers->create(new SaveCarrier('Courier', rates: [new SaveShippingRate('Bulgaria', '5', regions: ['BG']), new SaveShippingRate('Free', '0', regions: ['BG'], subtotal: new SaveRange('100'))]))->rates[0]->id;
        $this->shop->paymentMethods->update('bank_transfer', new SavePaymentMethod('Bank transfer', true));
        $this->shop->currencies->save(new CurrencySettingsView([new OfferedCurrencyView('USD', '1.1')]));
    }

    public function testAShopperWhoChoseACurrencyIsPricedAndOrdersInIt(): void
    {
        $this->shop->chosenCurrency->currency = 'USD';
        $owner = $this->cart(2);
        self::assertSame(['USD', '36.66', '73.32'], [$this->shop->carts->cart($owner)->cart->currency, $this->shop->carts->cart($owner)->cart->lines[0]->unitPrice, $this->shop->carts->cart($owner)->cart->subtotal]);

        $summary = $this->shop->checkout->summary($owner, new CheckoutDetails('BG', shippingOption: "rate:{$this->rate}"));
        self::assertSame(['USD', '73.32', '5.5', '15.76', '94.58'], [$summary->currency, $summary->subtotal, $summary->shipping, (string) ((float) $summary->itemsTax + (float) $summary->shippingTax), $summary->total]);
        self::assertSame('36.68', $summary->freeShippingShort, 'free from 100 EUR, which is 110 USD');

        $placed = $this->shop->checkout->placeOrder($owner, $this->order());
        $order = $this->shop->orders->order($placed->number);
        self::assertSame(['USD', '94.58'], [$order->currency, $order->total]);
    }

    public function testCouponAmountsAndTheMinimumAreConvertedPercentagesAreNot(): void
    {
        $this->shop->coupons->create(new SaveCoupon('TEN', 'order_amount', '10', minSubtotal: '50'));
        $this->shop->chosenCurrency->currency = 'USD';
        $owner = $this->cart(1);

        try {
            $this->shop->checkout->applyCoupon($owner, new CouponCode('TEN'));
            self::fail('36.66 USD is under 50 EUR');
        } catch (RuleViolation $e) {
            self::assertSame('This code needs an order of at least 55 USD.', $e->getMessage());
        }
        $owner = $this->cart(2);
        self::assertSame('11', $this->shop->checkout->applyCoupon($owner, new CouponCode('TEN'))->discount);
    }

    public function testACurrencyTheShopNoLongerOffersIsTheShopsOwn(): void
    {
        $this->shop->chosenCurrency->currency = 'GBP';
        self::assertSame('EUR', $this->shop->checkout->summary($this->cart(1), new CheckoutDetails('BG'))->currency);

        $this->expectException(RuleViolation::class);
        $this->shop->currencies->save(new CurrencySettingsView([new OfferedCurrencyView('EUR', '1')]));
    }

    private function cart(int $quantity): CartOwner
    {
        return new CartOwner($this->shop->carts->add(new CartOwner(null), new AddToCart(10, quantity: $quantity))->newToken);
    }

    private function order(): PlaceOrder
    {
        return new PlaceOrder('ada@example.com', new AddressData('Ada', 'Lovelace', '1 Vitosha Blvd', 'Sofia', '1000', 'BG', '+359 2 000 0000'), shippingOption: "rate:{$this->rate}", payment: 'bank_transfer');
    }
}
