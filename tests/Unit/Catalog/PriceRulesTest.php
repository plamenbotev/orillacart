<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Catalog;

use OrillaCart\Cart\Application\Dto\AddToCart;
use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Catalog\Application\Dto\PriceTierData;
use OrillaCart\Catalog\Application\Dto\QuoteRequest;
use OrillaCart\Catalog\Application\Dto\SavePriceRule;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Catalog\Application\Dto\SaveSale;
use OrillaCart\Catalog\Domain\Event\PriceRulesChanged;
use OrillaCart\Checkout\Application\Dto\CheckoutDetails;
use OrillaCart\Checkout\Application\Dto\CouponCode;
use OrillaCart\Coupons\Application\Dto\SaveCoupon;
use OrillaCart\Customers\Application\Dto\SaveCustomerGroup;
use OrillaCart\Customers\Application\Dto\SetCustomerGroups;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use PHPUnit\Framework\TestCase;

final class PriceRulesTest extends TestCase
{
    private TestShop $shop;

    protected function setUp(): void
    {
        $this->shop = new TestShop();
        $this->shop->content->add(10, 'Lamp');
        $this->shop->products->save(10, new SaveProduct('40', 'virtual'));
        $this->shop->content->terms[10] = ['categories' => [3], 'brands' => []];
        $this->shop->content->add(20, 'Bulb');
        $this->shop->products->save(20, new SaveProduct('5', 'virtual'));
    }

    public function testMoreItemsCostLessEachInTheCartAndThePageShowsTheTable(): void
    {
        $this->rule('Buy 10', [new PriceTierData(10, 'percent', '10'), new PriceTierData(50, 'fixed_price', '30')], categoryIds: [3]);

        self::assertSame([['min_quantity' => 1, 'price' => '40'], ['min_quantity' => 10, 'price' => '36'], ['min_quantity' => 50, 'price' => '30']], $this->shop->catalog->offer(10)->tiers);
        self::assertSame([], $this->shop->catalog->offer(20)->tiers, 'not in the category');
        self::assertSame(['40', '36'], [$this->unit(10, 9), $this->unit(10, 10)]);

        $owner = new CartOwner($this->shop->carts->add(new CartOwner(null), new AddToCart(10, quantity: 12))->newToken);
        self::assertSame('432', $this->shop->carts->cart($owner)->cart->subtotal);
        self::assertSame('432', $this->shop->checkout->summary($owner, new CheckoutDetails())->subtotal);
    }

    public function testAPercentageOffIsRoundedToTheShopsDecimalsWhereverThePriceShows(): void
    {
        $this->shop->products->save(10, new SaveProduct('33.33', 'virtual'));
        $this->rule('Buy 10', [new PriceTierData(10, 'percent', '10')], categoryIds: [3]);

        // 10 % off 33.33 is 29.997: the page, the cart and the order all say 30.
        self::assertSame([['min_quantity' => 1, 'price' => '33.33'], ['min_quantity' => 10, 'price' => '30']], $this->shop->catalog->offer(10)->tiers);
        self::assertSame('30', $this->unit(10, 10));
        $owner = new CartOwner($this->shop->carts->add(new CartOwner(null), new AddToCart(10, quantity: 10))->newToken);
        self::assertSame(['300', '300'], [$this->shop->carts->cart($owner)->cart->subtotal, $this->shop->checkout->summary($owner, new CheckoutDetails())->subtotal]);
    }

    public function testACustomerGroupSeesItsOwnPriceAndGuestsDoNot(): void
    {
        $wholesale = $this->shop->customerGroups->create(new SaveCustomerGroup('Wholesale'))->id;
        $customer = $this->shop->accounts->add('trade@example.com');
        $this->shop->customerGroups->setGroupsOf($customer, new SetCustomerGroups([$wholesale]));
        $this->rule('Wholesale', [new PriceTierData(1, 'amount_off', '8')], groupIds: [$wholesale]);

        self::assertSame(['40', '32'], [$this->shop->catalog->offer(10)->price, $this->shop->catalog->offer(10, $customer)->price]);
        self::assertSame('40', $this->shop->catalog->offer(10, $customer)->regularPrice, 'shown struck through');
        self::assertSame(['40', '32'], [$this->unit(10, 1), $this->unit(10, 1, $customer)]);
    }

    public function testTheLowestPriceWinsAndRulesLeaveSalesAloneUnlessTheySaySo(): void
    {
        $this->rule('Ten off', [new PriceTierData(1, 'percent', '10')]);
        $this->rule('Twenty off', [new PriceTierData(1, 'percent', '20')], productIds: [10]);
        self::assertSame(['32', '4.5'], [$this->unit(10, 1), $this->unit(20, 1)]);

        $this->shop->products->save(20, new SaveProduct('5', 'virtual', sale: new SaveSale('4')));
        self::assertSame('4', $this->unit(20, 1), 'on sale: the rules keep out');
        $this->rule('On sales too', [new PriceTierData(1, 'percent', '50')], productIds: [20], withSales: true);
        self::assertSame('2', $this->unit(20, 1), 'half the sale price');
    }

    public function testARuleThatCombinesTakesItsShareOffThePriceTheOthersGive(): void
    {
        $this->rule('Ten off', [new PriceTierData(1, 'percent', '10')]);
        $this->rule('Twenty off', [new PriceTierData(1, 'percent', '20')], productIds: [10]);
        $this->rule('Two off', [new PriceTierData(1, 'amount_off', '2')], combines: true);
        $this->rule('Half off from 5', [new PriceTierData(5, 'percent', '50')], combines: true);

        // 40 less 20 % is 32, less 2 is 30; the bulb: 5 less 10 % is 4.5, less 2 is 2.5.
        self::assertSame(['30', '2.5'], [$this->unit(10, 1), $this->unit(20, 1)]);
        self::assertSame('15', $this->unit(10, 5), 'half of 30 from 5');
        self::assertSame([['min_quantity' => 1, 'price' => '30'], ['min_quantity' => 5, 'price' => '15']], $this->shop->catalog->offer(10)->tiers);

        // A combining price of its own that is not lower leaves the price alone.
        $this->rule('Thirty-five', [new PriceTierData(1, 'fixed_price', '35')], productIds: [10], combines: true);
        self::assertSame('30', $this->unit(10, 1));
    }

    public function testARuleCanKeepCodesAndAutomaticDiscountsOffItsPrice(): void
    {
        $this->shop->coupons->create(new SaveCoupon('TEN', 'percent', '10', '10 % off'));
        $this->rule('Trade price', [new PriceTierData(1, 'fixed_price', '30')], productIds: [10], withCoupons: false);

        $owner = new CartOwner($this->shop->carts->add(new CartOwner(null), new AddToCart(10))->newToken);
        $this->shop->carts->add($owner, new AddToCart(20));
        $summary = $this->shop->checkout->applyCoupon($owner, new CouponCode('TEN'));
        self::assertSame(['0', '0.5'], [$summary->lines[0]->discount, $summary->lines[1]->discount], 'the lamp at its trade price keeps the code out; the bulb takes it');

        $this->shop->coupons->create(new SaveCoupon('', 'order_amount', '5', '5 off', automatic: true, combines: true));
        $summary = $this->shop->checkout->summary($owner, new CheckoutDetails());
        self::assertSame('0', $summary->lines[0]->discount, 'nor does an automatic discount come off it');

        // A code the shop made to apply even there (a staff code) comes off it.
        $only = new CartOwner($this->shop->carts->add(new CartOwner(null), new AddToCart(10))->newToken);
        $this->shop->coupons->create(new SaveCoupon('STAFF', 'percent', '20', 'Staff', overridesPriceRules: true));
        self::assertSame('6', $this->shop->checkout->applyCoupon($only, new CouponCode('STAFF'))->lines[0]->discount, '20 % of the trade price');
        $this->shop->checkout->removeCoupon($only, 'STAFF');

        $this->expectExceptionMessage('This code does not apply to the items in your cart.');
        $this->shop->checkout->applyCoupon($only, new CouponCode('TEN'));
    }

    public function testCodesComeOffWhenACombiningRuleThatKeepsThemOutDoesNotLowerThePrice(): void
    {
        $this->shop->coupons->create(new SaveCoupon('TEN', 'percent', '10', '10 % off'));
        $this->rule('Ten off', [new PriceTierData(1, 'percent', '10')]);
        $this->rule('Members from 3', [new PriceTierData(3, 'percent', '5')], combines: true, withCoupons: false);

        $owner = new CartOwner($this->shop->carts->add(new CartOwner(null), new AddToCart(10))->newToken);
        self::assertSame('3.6', $this->shop->checkout->applyCoupon($owner, new CouponCode('TEN'))->lines[0]->discount, 'one lamp: the members\' rule does not price it');
        $this->shop->carts->add($owner, new AddToCart(10, quantity: 2));
        self::assertSame('0', $this->shop->checkout->summary($owner, new CheckoutDetails())->lines[0]->discount, 'three: it does');
    }

    public function testARuleWaitsForItsDatesAndTheIndexLearnsWhenTheGuestPriceChanges(): void
    {
        $changed = 0;
        $this->shop->events->addListener(PriceRulesChanged::class, static function () use (&$changed): void {
            ++$changed;
        });
        $tomorrow = $this->shop->clock->now()->modify('+1 day');
        $this->rule('Weekend', [new PriceTierData(1, 'percent', '25')], startsAt: $tomorrow->format(\DATE_ATOM));

        self::assertSame('40', $this->unit(10, 1));
        self::assertEquals($tomorrow, $this->shop->catalog->nextPriceChange(10));
        self::assertSame(1, $changed, 'the search index is built again');

        $this->shop->clock->modify('+1 day');
        self::assertSame('30', $this->unit(10, 1));
    }

    /**
     * @param list<PriceTierData> $tiers
     * @param list<int>           $groupIds
     * @param list<int>           $productIds
     * @param list<int>           $categoryIds
     */
    private function rule(string $name, array $tiers, array $groupIds = [], array $productIds = [], array $categoryIds = [], bool $withSales = false, ?string $startsAt = null, bool $withCoupons = true, bool $combines = false): void
    {
        $this->shop->priceRules->create(new SavePriceRule($name, $tiers, $groupIds, $productIds, $categoryIds, startsAt: $startsAt, withSales: $withSales, withCoupons: $withCoupons, combines: $combines));
    }

    private function unit(int $product, int $quantity, ?int $customer = null): string
    {
        return $this->shop->quotes->quote(new QuoteRequest($product, [], $quantity, $customer))->unitPrice;
    }
}
