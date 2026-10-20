<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Checkout;

use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Checkout\Application\Dto\CreateOrder;
use OrillaCart\Checkout\Application\Dto\EditedItem;
use OrillaCart\Checkout\Application\Dto\QuoteOrder;
use OrillaCart\Checkout\Domain\CheckoutRuleViolation;
use OrillaCart\Coupons\Application\Dto\SaveCoupon;
use OrillaCart\Customers\Domain\CustomerNotFound;
use OrillaCart\Geography\Domain\PostcodeFormat;
use OrillaCart\Inventory\Application\Dto\SaveStockroom;
use OrillaCart\Inventory\Application\Dto\SetStockLevel;
use OrillaCart\Inventory\Application\Dto\SetStockLevels;
use OrillaCart\Inventory\Application\Dto\StockItemRef;
use OrillaCart\Ordering\Application\Dto\AddressData;
use OrillaCart\Payment\Application\Dto\PaymentOptionView;
use OrillaCart\Payment\Application\Dto\SavePaymentMethod;
use OrillaCart\Settings\Domain\DimensionUnit;
use OrillaCart\Settings\Domain\StoreSettings;
use OrillaCart\Settings\Domain\WeightUnit;
use OrillaCart\Shared\Domain\Currency;
use OrillaCart\Shipping\Application\Dto\SaveCarrier;
use OrillaCart\Shipping\Application\Dto\SaveShippingRate;
use OrillaCart\Tax\Application\Dto\SaveTaxGroup;
use OrillaCart\Tax\Application\Dto\SaveTaxRate;
use OrillaCart\Tax\Domain\TaxBasis;
use OrillaCart\Tax\Domain\TaxSettings;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use PHPUnit\Framework\TestCase;

final class ManualOrdersTest extends TestCase
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
        $this->shop->products->save(10, new SaveProduct('30', weight: '2'));
        $this->shop->content->add(20, 'E-book');
        $this->shop->products->save(20, new SaveProduct('10', 'virtual'));
        $room = $this->shop->stockrooms->create(new SaveStockroom('Sofia'))->id;
        $this->shop->stock->setLevels(StockItemRef::product(10), new SetStockLevels([new SetStockLevel($room, 5)]));
        $this->rate = $this->shop->carriers->create(new SaveCarrier('Courier', rates: [new SaveShippingRate('Bulgaria', '5', regions: ['BG'])]))->rates[0]->id;
        $this->shop->paymentMethods->update('bank_transfer', new SavePaymentMethod('Bank transfer', true));
    }

    public function testTheShopPricesAnOrderBeforeTakingIt(): void
    {
        $quote = $this->shop->manualOrders->quote(new QuoteOrder([new EditedItem(10, 2, unitPrice: '25')], 'BG'));

        self::assertSame([['Lamp', 2, '25', '50']], array_map(static fn($line): array => [$line->name, $line->quantity, $line->unitPrice, $line->total], $quote->lines));
        self::assertSame(['rate:' . $this->rate], array_map(static fn($option): string => $option->id, $quote->shippingOptions));
        self::assertSame('rate:' . $this->rate, $quote->shippingOption, 'the first rate counts in the totals until one is chosen');
        // 50 + 10 VAT + 5 shipping + 1 VAT on shipping
        self::assertSame('66', $quote->total);
        self::assertSame(['bank_transfer'], array_map(static fn(PaymentOptionView $option): string => $option->gateway, $quote->paymentOptions));
    }

    public function testTheShopsAutomaticDiscountsHoldForOrdersItTakes(): void
    {
        $discount = $this->shop->coupons->create(new SaveCoupon('', 'percent', '10', '10 % off orders over 40', minSubtotal: '40', automatic: true));

        $quote = $this->shop->manualOrders->quote(new QuoteOrder([new EditedItem(10, 2, unitPrice: '25')], 'BG'));
        self::assertSame(['5', '10 % off orders over 40'], [$quote->discount, $quote->discounts[0]->description ?? null]);
        // 45 + 9 VAT + 5 shipping + 1 VAT on shipping
        self::assertSame('60', $quote->total);

        $order = $this->shop->manualOrders->create(new CreateOrder('ada@example.com', self::address('BG'), [new EditedItem(10, 2, unitPrice: '25')], shippingOption: 'rate:' . $this->rate, payment: 'bank_transfer'), 7);

        self::assertSame(['60', '5'], [$order->total, $order->discountTotal]);
        self::assertSame(1, $this->shop->coupons->coupon($discount->id)->uses, 'its use is counted');
    }

    public function testAnUnpaidOrderWaitsOnHoldWithItsStockTaken(): void
    {
        $order = $this->shop->manualOrders->create(new CreateOrder('ada@example.com', self::address('BG'), [new EditedItem(10, 2, unitPrice: '25')], shippingOption: 'rate:' . $this->rate, payment: 'bank_transfer'), 7);

        self::assertSame(['on_hold', 'Bank transfer', '66'], [$order->status, $order->paymentName, $order->total]);
        self::assertSame(3, $this->available());
        self::assertContains('Taken by the shop.', array_map(static fn($note): string => $note->text, $order->notes));
    }

    public function testTheCustomerIsEmailedOnlyWhenTheShopAsksAndTheShopNeverIs(): void
    {
        $quiet = $this->shop->manualOrders->create(new CreateOrder('ada@example.com', self::address('BG'), [new EditedItem(20, 1)], payment: 'bank_transfer'), 7);
        self::assertSame([], $this->shop->mail->summary(), 'nobody hears of it');

        $told = $this->shop->manualOrders->create(new CreateOrder('ada@example.com', self::address('BG'), [new EditedItem(20, 1)], payment: 'bank_transfer', emailCustomer: true), 7);
        self::assertSame(["ada@example.com: [Lamp Shop] We received your order #{$told->number}"], $this->shop->mail->summary(), 'the customer, not the shop that took it');
        self::assertSame('on_hold', $quiet->status);
    }

    public function testAPaidOrderStartsProcessing(): void
    {
        $order = $this->shop->manualOrders->create(new CreateOrder('ada@example.com', self::address('BG'), [new EditedItem(20, 1)], paid: true), 7);

        self::assertSame('processing', $order->status);
        self::assertNotNull($order->paidAt);
    }

    public function testAPostcodeLooksLikeItsCountrysAndIsKeptInCapitals(): void
    {
        $london = new AddressData('Ada', 'Lovelace', '10 Downing St', 'London', 'sw1a   2aa', 'GB', '+44 20 0000 0000');
        self::assertSame('SW1A 2AA', $this->shop->manualOrders->create(new CreateOrder('ada@example.com', $london, [new EditedItem(20, 1)]), 7)->billing->postcode);

        $this->expectException(CheckoutRuleViolation::class);
        $this->expectExceptionMessage('The postcode of the billing address is not one of Bulgaria, which look like 1000.');
        $this->shop->manualOrders->create(new CreateOrder('ada@example.com', new AddressData('Ada', 'Lovelace', '1 Vitosha Blvd', 'Sofia', '10000', 'BG', '+359 2 000 0000'), [new EditedItem(20, 1)]), 7);
    }

    public function testTheShopMayTakeOrdersFromCountriesCustomersCannotOrderFrom(): void
    {
        $this->shop->settings->save(new StoreSettings(new Currency('EUR'), 2, WeightUnit::Kilogram, DimensionUnit::Centimetre, 'BG', sellTo: ['BG']));

        $order = $this->shop->manualOrders->create(new CreateOrder('max@example.com', self::address('DE'), [new EditedItem(20, 1)]), 7);

        self::assertSame(['on_hold', 'DE'], [$order->status, $order->billing->country]);
    }

    public function testAnOrderThatShipsNeedsARateAndAnAccountMustExist(): void
    {
        try {
            $this->shop->manualOrders->create(new CreateOrder('ada@example.com', self::address('BG'), [new EditedItem(10, 1)]), 7);
            self::fail('no rate');
        } catch (CheckoutRuleViolation $e) {
            self::assertSame('orillacart_shipping_required', $e->errorCode);
        }
        self::assertSame(5, $this->available(), 'nothing was taken');

        $this->expectException(CustomerNotFound::class);
        $this->shop->manualOrders->create(new CreateOrder('ada@example.com', self::address('BG'), [new EditedItem(20, 1)], customerId: 99), 7);
    }

    private function available(): ?int
    {
        return $this->shop->stock->stock(StockItemRef::product(10))->available;
    }

    private static function address(string $country): AddressData
    {
        return new AddressData('Ada', 'Lovelace', '1 Vitosha Blvd', 'Sofia', PostcodeFormat::example($country) ?? '1000', $country, '+359 2 000 0000');
    }
}
