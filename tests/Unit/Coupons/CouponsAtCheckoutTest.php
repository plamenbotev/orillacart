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
        $order = $this->shop->orders->order($placed->number);
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

    public function testEditingAFirstOrderKeepsItsFirstOrderCode(): void
    {
        $this->coupon('WELCOME', 'percent', '10', firstOrderOnly: true);
        $owner = $this->cart([10, 1]);
        $this->shop->checkout->applyCoupon($owner, new CouponCode('WELCOME'));
        $placed = $this->shop->checkout->placeOrder($owner, $this->order());

        $edited = $this->shop->editing->edit($placed->number, new EditOrderItems([new EditedItem(10, 2)], "rate:{$this->rate}"), 1);

        self::assertSame(['8', 'WELCOME'], [$edited->discountTotal, $edited->discounts[0]->code ?? null], 'the order being edited is not an earlier order');
    }

    public function testAnAutomaticDiscountAppliesWhereItHoldsWithoutACode(): void
    {
        $discount = $this->shop->coupons->create(new SaveCoupon('', 'percent', '10', '10 % off orders over 60', minSubtotal: '60', combines: true, automatic: true));
        self::assertStringStartsWith('AUTO-', $discount->code);

        $small = $this->cart([10, 1]);
        self::assertSame('0', $this->shop->checkout->summary($small, new CheckoutDetails())->discount, 'under 60');

        $large = $this->cart([10, 2]);
        $summary = $this->shop->checkout->summary($large, new CheckoutDetails());
        self::assertSame([['10 % off orders over 60', '8', true]], array_map(static fn($coupon): array => [$coupon->description, $coupon->amount, $coupon->automatic], $summary->coupons));
        try {
            $this->shop->checkout->applyCoupon($large, new CouponCode($discount->code));
            self::fail('its code is not for entering');
        } catch (CheckoutRuleViolation $e) {
            self::assertSame('This code is not valid.', $e->getMessage());
        }

        $this->coupon('TEN', 'order_amount', '10', combines: true);
        $this->shop->checkout->applyCoupon($large, new CouponCode('TEN'));
        $placed = $this->shop->checkout->placeOrder($large, $this->order());
        $order = $this->shop->orders->order($placed->number);
        self::assertSame([[true, '8'], [false, '10']], array_map(static fn($used): array => [$used->automatic, $used->amount], $order->discounts), 'the code takes off what the automatic discount left');
        self::assertSame(1, $this->shop->coupons->coupon($discount->id)->uses);
    }

    public function testAnAutomaticDiscountGoesWithCodesAndACodeThatStandsAloneSaysWhy(): void
    {
        $this->shop->coupons->create(new SaveCoupon('', 'percent', '10', '10 % off orders over 60', minSubtotal: '60', automatic: true));
        $this->coupon('TEN', 'order_amount', '10', combines: true);
        $this->coupon('ALONE', 'order_amount', '5');
        $cart = $this->cart([10, 2]);

        $this->shop->checkout->applyCoupon($cart, new CouponCode('TEN'));
        self::assertSame(['8', '10'], array_map(static fn($coupon): string => $coupon->amount, $this->shop->checkout->summary($cart, new CheckoutDetails())->coupons), 'an automatic discount that does not combine still goes with a code');

        $this->shop->checkout->removeCoupon($cart, 'TEN');
        try {
            $this->shop->checkout->applyCoupon($cart, new CouponCode('ALONE'));
            self::fail('a code that stands alone');
        } catch (CheckoutRuleViolation $e) {
            self::assertSame('This code cannot be combined with 10 % off orders over 60.', $e->getMessage());
        }
    }

    public function testTheOrderSizeACodeNeedsIsWhatTheItemsCostBeforeDiscounts(): void
    {
        $this->shop->coupons->create(new SaveCoupon('', 'percent', '10', '10 % off orders over 60', minSubtotal: '60', combines: true, automatic: true));
        $this->coupon('BIG', 'order_amount', '5', minSubtotal: '75', combines: true);
        $cart = $this->cart([10, 2]);

        $this->shop->checkout->applyCoupon($cart, new CouponCode('BIG'));

        self::assertSame(['8', '5'], array_map(static fn($coupon): string => $coupon->amount, $this->shop->checkout->summary($cart, new CheckoutDetails())->coupons), '80 of items, though 72 is left after the first discount');
    }

    public function testAPerCustomerLimitCountsTheAccountBeforeTheEmailIsKnown(): void
    {
        $ada = $this->shop->accounts->add('ada@example.com', 'Ada');
        $this->coupon('ONCE', 'order_amount', '5', perCustomerLimit: 1);
        $first = $this->cart([10, 1]);
        $first = new CartOwner($first->token, $ada);
        $this->shop->checkout->applyCoupon($first, new CouponCode('ONCE'));
        $this->shop->checkout->placeOrder($first, $this->order());

        $again = new CartOwner($this->cart([10, 1])->token, $ada);
        $this->expectExceptionMessage('You have already used this code.');
        $this->shop->checkout->applyCoupon($again, new CouponCode('ONCE'));
    }

    public function testBuyTwoGetOneFreeGivesTheCheapestItems(): void
    {
        $this->shop->coupons->create(new SaveCoupon('THREE', 'buy_get', '100', buyQuantity: 2, getQuantity: 1));

        $owner = $this->cart([10, 2], [20, 1]);
        $summary = $this->shop->checkout->applyCoupon($owner, new CouponCode('THREE'));
        self::assertSame(['0', '10'], [$summary->lines[0]->discount, $summary->lines[1]->discount], 'two lamps bought, the shade given');

        $owner = $this->cart([10, 3], [20, 3]);
        $summary = $this->shop->checkout->applyCoupon($owner, new CouponCode('THREE'));
        self::assertSame(['0', '20'], [$summary->lines[0]->discount, $summary->lines[1]->discount], 'two rounds: two lamps and a shade, then a lamp, a shade and a shade');

        $this->expectExceptionMessage('This code needs 3 of the items it applies to: 2 to buy and 1 it gives.');
        $this->shop->checkout->applyCoupon($this->cart([10, 2]), new CouponCode('THREE'));
    }

    public function testBuyingSomeItemsGivesOthersAPercentageOff(): void
    {
        $this->shop->coupons->create(new SaveCoupon('SHADE', 'buy_get', '50', productIds: [10], buyQuantity: 1, getQuantity: 1, getProductIds: [20]));

        $summary = $this->shop->checkout->applyCoupon($this->cart([10, 2], [20, 3]), new CouponCode('SHADE'));
        self::assertSame(['0', '10'], [$summary->lines[0]->discount, $summary->lines[1]->discount], 'a shade at half price for each lamp');

        $this->expectExceptionMessage('Add the items this code gives off to your cart.');
        $this->shop->checkout->applyCoupon($this->cart([10, 1]), new CouponCode('SHADE'));
    }

    public function testAFreeGiftIsAddedOnceTheOrderHoldsAndTheOrderKeepsIt(): void
    {
        $this->shop->coupons->create(new SaveCoupon('', 'free_gift', '0', 'A shade with orders over 50', minSubtotal: '50', combines: true, automatic: true, getQuantity: 1, giftProductId: 20));

        $small = $this->shop->checkout->summary($this->cart([10, 1]), new CheckoutDetails('BG'));
        self::assertCount(1, $small->lines, 'under 50');

        $owner = $this->cart([10, 2]);
        $summary = $this->shop->checkout->summary($owner, new CheckoutDetails('BG', shippingOption: "rate:{$this->rate}"));
        self::assertSame([['Lamp', 2, '80', '0', false], ['Shade', 1, '10', '10', true]], array_map(static fn($line): array => [$line->name, $line->quantity, $line->total, $line->discount, $line->gift], $summary->lines));
        self::assertSame(['90', '10', '16', '102'], [$summary->subtotal, $summary->discount, $summary->itemsTax, $summary->total], 'the gift costs nothing, and nothing in tax');
        self::assertSame([['A shade with orders over 50', '10']], array_map(static fn($coupon): array => [$coupon->description, $coupon->amount], $summary->coupons));

        $placed = $this->shop->checkout->placeOrder($owner, $this->order());
        $order = $this->shop->orders->order($placed->number);
        self::assertSame([['Lamp', '0'], ['Shade', '10']], array_map(static fn($line): array => [$line->name, $line->discount], $order->lines));
        self::assertSame('102', $order->total);

        $own = $this->shop->checkout->summary($this->cart([10, 2], [20, 2]), new CheckoutDetails('BG'));
        self::assertSame([['Lamp', 2, '0'], ['Shade', 2, '10']], array_map(static fn($line): array => [$line->name, $line->quantity, $line->discount], $own->lines), 'a shade in the cart is made free, none added; the gift does not count towards the order');
    }

    public function testAFreeGiftNamesItsProduct(): void
    {
        $this->expectException(CouponRuleViolation::class);
        $this->shop->coupons->create(new SaveCoupon('GIFT', 'free_gift', '0', getQuantity: 1));
    }

    public function testAnAutomaticDiscountNeedsADescription(): void
    {
        $this->expectException(CouponRuleViolation::class);
        $this->shop->coupons->create(new SaveCoupon('', 'percent', '10', automatic: true));
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
