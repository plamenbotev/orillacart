<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Ordering;

use OrillaCart\Cart\Application\Dto\AddToCart;
use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Checkout\Application\Dto\CouponCode;
use OrillaCart\Checkout\Application\Dto\PlaceOrder;
use OrillaCart\Coupons\Application\Dto\SaveCoupon;
use OrillaCart\Extension\Checkout\Fee;
use OrillaCart\Extension\Checkout\FeeQuery;
use OrillaCart\Extension\Checkout\OrderFee;
use OrillaCart\Ordering\Application\Dto\AddressData;
use OrillaCart\Ordering\Application\Dto\LineTaxView;
use OrillaCart\Ordering\Application\Dto\OrderLineView;
use OrillaCart\Payment\Application\Dto\MakeRefund;
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
 * An order keeps its money as lines: the items, the shipping and each fee, every one with each rate charged on
 * it, by jurisdiction, and the changes to its price. Totals, refunds and returns come from those.
 */
final class OrderMoneyTest extends TestCase
{
    private TestShop $shop;
    private int $rate;

    protected function setUp(): void
    {
        $this->shop = new TestShop();
        $group = $this->shop->taxGroups->create(new SaveTaxGroup('Standard'))->id;
        $this->shop->taxGroups->addRate($group, new SaveTaxRate('US', 'CA', '6', 'California'));
        $this->shop->taxGroups->addRate($group, new SaveTaxRate('US', 'CA', '2.5', 'Los Angeles', postcodes: ['900*'], code: 'CA-LA'));
        $this->shop->taxSettings->save(new TaxSettings(TaxBasis::ShippingAddress, $group));
        $this->shop->content->add(10, 'Lamp');
        $this->shop->products->save(10, new SaveProduct('100', weight: '1', sku: 'LAMP', stockMode: 'always_available'));
        $this->rate = $this->shop->carriers->create(new SaveCarrier('Courier', rates: [new SaveShippingRate('US', '10', regions: ['US'])]))->rates[0]->id;
        $this->shop->paymentMethods->update('bank_transfer', new SavePaymentMethod('Bank transfer', true));
        $this->shop->extensions->fees[] = new class implements OrderFee {
            public function id(): string
            {
                return 'wrapping';
            }

            public function fee(FeeQuery $query): Fee
            {
                return new Fee('Gift wrapping', '4');
            }
        };
        $this->shop->coupons->create(new SaveCoupon('SPRING', 'percent', '10', 'Spring sale'));
    }

    public function testEveryLineKeepsItsTaxesByJurisdictionAndItsAdjustments(): void
    {
        $order = $this->shop->orders->order($this->place());

        $lamp = $order->lines[0];
        self::assertSame(['200', '-20', '15.3'], [$lamp->total, $lamp->adjustments[0]->amount, $lamp->tax], 'two lamps, 10 % off, 6 % and 2.5 % of 180');
        self::assertSame(['coupon', 'SPRING', 'Spring sale', 'order'], [$lamp->adjustments[0]->kind, $lamp->adjustments[0]->source, $lamp->adjustments[0]->label, $lamp->adjustments[0]->scope]);
        self::assertSame([['US-CA', 'region', '180', '10.8'], ['CA-LA', 'local', '180', '4.5']], self::rows($lamp));

        self::assertSame(['shipping', 'fee'], array_column($order->charges, 'kind'));
        [$shipping, $wrapping] = $order->charges;
        self::assertSame(['Courier – US', '10', '0.85'], [$shipping->name, $shipping->total, $shipping->tax]);
        self::assertSame(['Gift wrapping', '4', '0.34'], [$wrapping->name, $wrapping->total, $wrapping->tax]);

        self::assertSame(['10', '0.85', '4', '0.34', '200', '20', '15.3', '16.49'], [$order->shippingTotal, $order->shippingTax, $order->feesTotal, $order->feesTax, $order->subtotal, $order->discountTotal, $order->itemsTax, $order->tax]);
        self::assertSame('210.49', $order->total, '180 + 10 + 4 and 16.49 of tax');
        self::assertSame([['California', '6', '11.64'], ['Los Angeles', '2.5', '4.85']], array_map(static fn($tax): array => [$tax->name, $tax->percent, $tax->amount], $order->taxes));
        self::assertSame([['SPRING', 'Spring sale', '20']], array_map(static fn($discount): array => [$discount->code, $discount->description, $discount->amount], $order->discounts));
    }

    public function testARefundGivesBackTheTaxOfWhatItCoversRateByRate(): void
    {
        $number = $this->place();
        $this->shop->orders->markPaidByShop($number, '', 7);

        $this->shop->refunds->refund($number, new MakeRefund('10.85', shipping: true), 7);
        $order = $this->shop->orders->order($number);
        self::assertTrue($order->shippingRefunded);
        self::assertSame([['US-CA', '10', '0.6', '0.6'], ['CA-LA', '10', '0.25', '0.25']], array_map(static fn(LineTaxView $tax): array => [$tax->code, $tax->taxable, $tax->amount, $tax->refunded], $order->charges[0]->taxes), 'the shipping\'s tax, rate by rate');
        self::assertSame(['0', '0'], array_map(static fn(LineTaxView $tax): string => $tax->refunded, $order->lines[0]->taxes), 'the items keep theirs');

        $this->shop->refunds->refund($number, new MakeRefund('199.64', outside: true), 7);
        $order = $this->shop->orders->order($number);
        $rows = [...$order->lines[0]->taxes, ...$order->charges[0]->taxes, ...$order->charges[1]->taxes];
        self::assertSame(
            array_map(static fn(LineTaxView $tax): string => $tax->amount, $rows),
            array_map(static fn(LineTaxView $tax): string => $tax->refunded, $rows),
            'the refund that empties the order gives back the rest of every rate',
        );
        self::assertSame('refunded', $order->status);
    }

    private function place(): int
    {
        $owner = new CartOwner($this->shop->carts->add(new CartOwner(null), new AddToCart(10, quantity: 2))->newToken);
        $this->shop->checkout->applyCoupon($owner, new CouponCode('SPRING'));
        $address = new AddressData('Ada', 'Lovelace', '1 Main St', 'Los Angeles', '90012', 'US', '+1 213 000 0000', 'CA');

        return $this->shop->checkout->placeOrder($owner, new PlaceOrder('ada@example.com', $address, shippingOption: "rate:{$this->rate}", payment: 'bank_transfer'))->number;
    }

    /** @return list<array{string, string, string, string}> */
    private static function rows(OrderLineView $line): array
    {
        return array_map(static fn(LineTaxView $tax): array => [$tax->code, $tax->level, (string) Money::of($tax->taxable), (string) Money::of($tax->amount)], $line->taxes);
    }
}
