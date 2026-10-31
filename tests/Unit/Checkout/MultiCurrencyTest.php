<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Checkout;

use OrillaCart\Cart\Application\Dto\AddToCart;
use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Checkout\Application\Dto\AdjustOrderPrice;
use OrillaCart\Checkout\Application\Dto\CheckoutDetails;
use OrillaCart\Checkout\Application\Dto\CouponCode;
use OrillaCart\Checkout\Application\Dto\PlaceOrder;
use OrillaCart\Coupons\Application\Dto\SaveCoupon;
use OrillaCart\Ordering\Application\Dto\AddressData;
use OrillaCart\Ordering\Application\Dto\RefundLine;
use OrillaCart\Payment\Application\Dto\MakeRefund;
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
        self::assertSame(['36.66', '73.32'], [$summary->lines[0]->unitPrice, $summary->lines[0]->total], 'each in USD, as the cart page shows it, not the 33.33 EUR it costs in the shop');
        self::assertSame('36.68', $summary->freeShippingShort, 'free from 100 EUR, which is 110 USD');

        $placed = $this->shop->checkout->placeOrder($owner, $this->order());
        $order = $this->shop->orders->order($placed->number);
        self::assertSame(['USD', '94.58'], [$order->currency, $order->total]);
    }

    public function testTheOrderKeepsItsExchangeRateAndItsInvoiceShowsTheTaxInTheShopsCurrency(): void
    {
        $this->shop->chosenCurrency->currency = 'USD';
        $placed = $this->shop->checkout->placeOrder($this->cart(2), $this->order());
        $order = $this->shop->orders->order($placed->number);
        self::assertSame(['EUR', '1.1'], [$order->shopCurrency, $order->exchangeRate]);

        $this->shop->orders->markPaidByShop($order->number, '', 7);
        $invoice = $this->shop->invoices->ofOrder($order->number)[0]->number;
        $document = $this->shop->invoices->document($invoice, $this->shop->receipts->issue($order->number));
        // 15.76 USD of tax (14.66 on the items, 1.10 on shipping) is 14.33 EUR at 1.1.
        self::assertSame(['EUR', '1.1', [['VAT', '20', '14.33']], '14.33'], [
            $document->inShopCurrency['currency'] ?? null,
            $document->inShopCurrency['rate'] ?? null,
            array_map(static fn(array $tax): array => array_values($tax), $document->inShopCurrency['taxes'] ?? []),
            $document->inShopCurrency['total'] ?? null,
        ]);
        $html = $this->shop->templates->render('invoices/document.html.twig', ['document' => $document, 'locale' => 'en-US']);
        self::assertStringContainsString('Tax in EUR, at 1 EUR = 1.1 USD', $html);

        // A credit note gives back its own tax, in the shop's currency at the same rate.
        $this->shop->refunds->refund($order->number, new MakeRefund('44', [new RefundLine(0, 1)]), 7);
        $credit = array_values(array_filter($this->shop->invoices->ofOrder($order->number), static fn($issued): bool => $issued->kind === 'credit_note'))[0]->number;
        $given = $this->shop->invoices->document($credit, $this->shop->receipts->issue($order->number))->inShopCurrency;
        self::assertSame('1.1', $given['rate'] ?? null);
        self::assertNotSame('0', $given['total'] ?? '0');
    }

    public function testAnOrderEditedIsPricedAgainInItsOwnCurrency(): void
    {
        $this->shop->chosenCurrency->currency = 'USD';
        $placed = $this->shop->checkout->placeOrder($this->cart(2), $this->order());
        $this->shop->chosenCurrency->currency = null;

        $edited = $this->shop->editing->adjust($placed->number, new AdjustOrderPrice('-1', 'Goodwill'), 7);

        self::assertSame(['USD', '36.66', '5.5'], [$edited->currency, $edited->lines[0]->unitPrice, $edited->shippingTotal], 'its shipping in USD, not 5 EUR called USD');
    }

    public function testAnOrderInTheShopsCurrencyKeepsNoRate(): void
    {
        $placed = $this->shop->checkout->placeOrder($this->cart(1), $this->order());
        $order = $this->shop->orders->order($placed->number);
        self::assertSame([null, null], [$order->shopCurrency, $order->exchangeRate]);
        $this->shop->orders->markPaidByShop($order->number, '', 7);
        self::assertNull($this->shop->invoices->document($this->shop->invoices->ofOrder($order->number)[0]->number, $this->shop->receipts->issue($order->number))->inShopCurrency);
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

    public function testAThresholdIsReachedByTheAmountThePageShowsForIt(): void
    {
        $this->shop->currencies->save(new CurrencySettingsView([new OfferedCurrencyView('BGN', '1.95583')]));
        $this->shop->carriers->create(new SaveCarrier('Post', rates: [new SaveShippingRate('Free from 50', '0', regions: ['BG'], subtotal: new SaveRange('50'))]));
        $this->shop->products->save(10, new SaveProduct('50', weight: '1'));
        $this->shop->chosenCurrency->currency = 'BGN';

        $summary = $this->shop->checkout->summary($this->cart(1), new CheckoutDetails('BG'));

        // 50 EUR is shown as 97.79 BGN; 97.79 BGN back is 49.9992 EUR, which is no less than the 50 it stands for.
        self::assertSame('97.79', $summary->subtotal);
        self::assertContains('Free from 50', array_map(static fn($option): string => $option->name, $summary->shippingOptions));
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
