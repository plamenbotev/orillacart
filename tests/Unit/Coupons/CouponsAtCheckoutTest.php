<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Coupons;

use OrillaCart\Cart\Application\Dto\AddToCart;
use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Checkout\Application\Dto\CheckoutDetails;
use OrillaCart\Checkout\Application\Dto\CouponCode;
use OrillaCart\Checkout\Application\Dto\EditedItem;
use OrillaCart\Checkout\Application\Dto\EditOrderItems;
use OrillaCart\Checkout\Application\Dto\PlaceOrder;
use OrillaCart\Checkout\Domain\CheckoutRuleViolation;
use OrillaCart\Coupons\Application\Dto\SaveCoupon;
use OrillaCart\Coupons\Domain\CouponRuleViolation;
use OrillaCart\Ordering\Application\Dto\AddressData;
use OrillaCart\Ordering\Application\Dto\ChangeOrderStatus;
use OrillaCart\Payment\Application\Dto\SavePaymentMethod;
use OrillaCart\Shipping\Application\Dto\SaveCarrier;
use OrillaCart\Shipping\Application\Dto\SaveShippingRate;
use OrillaCart\Tax\Application\Dto\SaveTaxGroup;
use OrillaCart\Tax\Application\Dto\SaveTaxRate;
use OrillaCart\Tax\Domain\TaxBasis;
use OrillaCart\Tax\Domain\TaxSettings;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use PHPUnit\Framework\TestCase;

final class CouponsAtCheckoutTest extends TestCase
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
        $this->shop->products->save(10, new SaveProduct('40', weight: '1'));
        $this->shop->content->terms[10] = ['categories' => [5, 1], 'brands' => []];
        $this->shop->content->add(20, 'Shade');
        $this->shop->products->save(20, new SaveProduct('10', weight: '1'));
        $this->rate = $this->shop->carriers->create(new SaveCarrier('Courier', rates: [new SaveShippingRate('Bulgaria', '5', regions: ['BG'])]))->rates[0]->id;
        $this->shop->paymentMethods->update('bank_transfer', new SavePaymentMethod('Bank transfer', true));
    }

    public function testAPercentageComesOffBeforeTaxAndTheOrderKeepsIt(): void
    {
        $this->coupon('SPRING', 'percent', '10', description: 'Spring sale');
        $owner = $this->cart([10, 1], [20, 1]);

        $this->shop->checkout->applyCoupon($owner, new CouponCode(' spring '));
        $summary = $this->shop->checkout->summary($owner, new CheckoutDetails('BG', shippingOption: "rate:{$this->rate}"));

        self::assertSame(['50', '5', '9', '5', '1', '60'], [$summary->subtotal, $summary->discount, $summary->itemsTax, $summary->shipping, $summary->shippingTax, $summary->total], 'tax is on 45');
        self::assertSame([['SPRING', 'Spring sale', '5', null]], array_map(static fn($coupon): array => [$coupon->code, $coupon->description, $coupon->amount, $coupon->refused], $summary->coupons));
        self::assertSame(['4', '1'], [$summary->lines[0]->discount, $summary->lines[1]->discount]);

        $placed = $this->shop->checkout->placeOrder($owner, $this->order());
        $order = $this->shop->orders->byKey($placed->key);
        self::assertSame(['60', '5', 'SPRING', '4'], [$order->total, $order->discountTotal, $order->discounts[0]->code, $order->lines[0]->discount]);
        self::assertSame(1, $this->shop->coupons->coupon(1)->uses);
    }

    public function testACodeThatDoesNotHoldIsRefusedWithTheReason(): void
    {
        $this->coupon('BIG', 'order_amount', '10', minSubtotal: '100');
        $this->coupon('OLD', 'percent', '5', endsAt: '2020-01-01T00:00:00+00:00');
        $owner = $this->cart([10, 1]);

        foreach (['NOPE' => 'This code is not valid.', 'BIG' => 'This code needs an order of at least 100 EUR.', 'OLD' => 'This code has expired.'] as $code => $reason) {
            try {
                $this->shop->checkout->applyCoupon($owner, new CouponCode($code));
                self::fail($code);
            } catch (CheckoutRuleViolation $e) {
                self::assertSame([$reason, 'orillacart_coupon_refused'], [$e->getMessage(), $e->errorCode]);
            }
        }
        self::assertSame([], $this->shop->checkout->summary($owner, new CheckoutDetails())->coupons, 'nothing was kept');
    }

    public function testACouponForACategoryLeavesTheOtherItemsAndAmountsNeverGoBelowNothing(): void
    {
        $this->coupon('LAMPS', 'item_amount', '50', categoryIds: [1]);
        $owner = $this->cart([10, 2], [20, 1]);

        $summary = $this->shop->checkout->applyCoupon($owner, new CouponCode('LAMPS'));

        self::assertSame(['80', '0'], [$summary->lines[0]->discount, $summary->lines[1]->discount], 'at most what the lamps cost; the parent category counts');
    }

    public function testCodesCombineOnlyWhenEachSaysSoAndFreeShippingZeroesTheRates(): void
    {
        $this->coupon('SHIP', 'free_shipping', '0', combines: true);
        $this->coupon('TEN', 'percent', '10', combines: true);
        $this->coupon('ALONE', 'order_amount', '5');
        $owner = $this->cart([10, 1]);

        $this->shop->checkout->applyCoupon($owner, new CouponCode('SHIP'));
        $summary = $this->shop->checkout->applyCoupon($owner, new CouponCode('TEN'));
        self::assertSame(['4', '0'], [$summary->discount, $summary->shipping]);
        $this->expectExceptionMessage('This code cannot be used together with your other codes.');
        $this->shop->checkout->applyCoupon($owner, new CouponCode('ALONE'));
    }

    public function testAUsedUpCodeIsRefusedAndACancelledOrderGivesItsUseBack(): void
    {
        $this->coupon('ONCE', 'percent', '10', usageLimit: 1);
        $first = $this->cart([10, 1]);
        $this->shop->checkout->applyCoupon($first, new CouponCode('ONCE'));
        $second = $this->cart([20, 1]);
        $this->shop->checkout->applyCoupon($second, new CouponCode('ONCE'));

        $placed = $this->shop->checkout->placeOrder($first, $this->order());
        try {
            $this->shop->checkout->placeOrder($second, $this->order());
            self::fail('used up');
        } catch (CheckoutRuleViolation $e) {
            self::assertSame('The code ONCE does not apply: This code has been used up. Remove it to order.', $e->getMessage());
        }

        $this->shop->orders->changeStatus($placed->number, new ChangeOrderStatus('cancelled'), 1);
        self::assertSame(0, $this->shop->coupons->coupon(1)->uses);
        self::assertSame('on_hold', $this->shop->checkout->placeOrder($second, $this->order())->status);
    }

    public function testACodeForFirstOrdersOrOncePerCustomerKnowsTheCustomerByEmail(): void
    {
        $this->coupon('WELCOME', 'percent', '10', firstOrderOnly: true);
        $this->coupon('MINE', 'percent', '5', perCustomerLimit: 1);
        $this->shop->checkout->placeOrder($this->cart([20, 1]), $this->order());

        foreach (['WELCOME' => 'This code is for a first order only.', 'MINE' => null] as $code => $reason) {
            $owner = $this->cart([10, 1]);
            $this->shop->checkout->applyCoupon($owner, new CouponCode($code));
            try {
                $this->shop->checkout->placeOrder($owner, $this->order());
                self::assertNull($reason, $code);
            } catch (CheckoutRuleViolation $e) {
                self::assertStringContainsString((string) $reason, $e->getMessage());
            }
        }
        $owner = $this->cart([10, 1]);
        $this->shop->checkout->applyCoupon($owner, new CouponCode('MINE'));
        $this->expectExceptionMessage('You have already used this code.');
        $this->shop->checkout->placeOrder($owner, $this->order());
    }

    public function testEditingAnOrderOnHoldPricesItsCouponAgain(): void
    {
        $this->coupon('TEN', 'percent', '10');
        $owner = $this->cart([10, 1]);
        $this->shop->checkout->applyCoupon($owner, new CouponCode('TEN'));
        $placed = $this->shop->checkout->placeOrder($owner, $this->order());

        $edited = $this->shop->editing->edit($placed->number, new EditOrderItems([new EditedItem(10, 2)], "rate:{$this->rate}"), 1);

        self::assertSame(['8', 'TEN'], [$edited->discountTotal, $edited->discounts[0]->code]);
        self::assertSame(1, $this->shop->coupons->coupon(1)->uses, 'still one use');
    }

    public function testTheShopCannotGiveTwoCouponsOneCode(): void
    {
        $this->coupon('SPRING', 'percent', '10');
        $this->expectException(CouponRuleViolation::class);
        $this->coupon('spring', 'percent', '20');
    }

    /**
     * @param list<int> $categoryIds
     */
    private function coupon(string $code, string $kind, string $amount, string $description = '', ?string $minSubtotal = null, ?string $endsAt = null, array $categoryIds = [], bool $combines = false, ?int $usageLimit = null, ?int $perCustomerLimit = null, bool $firstOrderOnly = false): void
    {
        $this->shop->coupons->create(new SaveCoupon($code, $kind, $amount, $description, minSubtotal: $minSubtotal, endsAt: $endsAt, categoryIds: $categoryIds, combines: $combines, usageLimit: $usageLimit, perCustomerLimit: $perCustomerLimit, firstOrderOnly: $firstOrderOnly));
    }

    /**
     * @param array{int, int} ...$lines
     */
    private function cart(array ...$lines): CartOwner
    {
        $owner = new CartOwner(null);
        foreach ($lines as [$product, $quantity]) {
            $owner = new CartOwner($this->shop->carts->add($owner, new AddToCart($product, quantity: $quantity))->newToken ?? $owner->token);
        }

        return $owner;
    }

    private function order(): PlaceOrder
    {
        return new PlaceOrder('ada@example.com', new AddressData('Ada', 'Lovelace', '1 Vitosha Blvd', 'Sofia', '1000', 'BG', '+359 2 000 0000'), shippingOption: "rate:{$this->rate}", payment: 'bank_transfer');
    }
}
