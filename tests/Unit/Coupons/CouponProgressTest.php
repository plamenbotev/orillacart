<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Coupons;

use OrillaCart\Coupons\Domain\Basket;
use OrillaCart\Coupons\Domain\BasketLine;
use OrillaCart\Coupons\Domain\Coupon;
use OrillaCart\Coupons\Domain\CouponKind;
use OrillaCart\Coupons\Domain\CouponTerms;
use OrillaCart\Shared\Domain\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What a cart still lacks for a coupon (Coupon::progress()), for each kind, and which coupons a shopper could
 * still have at all (Coupon::obtainable()): measured as refusal() measures, so a cart that holds is never refused.
 * A lamp (product 10, category 5) is 20, a book (product 20, category 6) 12, a shade (product 30) 8.
 */
final class CouponProgressTest extends TestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-10-31 12:00:00');
    }

    public function testASmallestOrderCountsTheWholeOrderNotOnlyTheItemsItIsFor(): void
    {
        $coupon = self::coupon(new CouponTerms('LAMPS', CouponKind::Percent, Money::of('10'), minSubtotal: Money::of('50'), categoryIds: [5]));
        $progress = $coupon->progress(self::basket(self::lamp(1), self::book(1)));

        self::assertNotNull($progress);
        self::assertSame(['32', '18', 0], [(string) $progress->spent, (string) $progress->short(), $progress->unitsShort()], 'the book counts towards the order\'s size');
        self::assertEqualsWithDelta(0.64, $progress->nearness(), 0.0001);
        self::assertTrue(self::coupon(new CouponTerms('LAMPS', CouponKind::Percent, Money::of('10'), minSubtotal: Money::of('30'), categoryIds: [5]))->progress(self::basket(self::lamp(1), self::book(1)))?->holds());
    }

    public function testAnAmountOffTakesItsSmallestNumberOfTheItemsItIsFor(): void
    {
        $coupon = self::coupon(new CouponTerms('GARDEN', CouponKind::OrderAmount, Money::of('5'), minQuantity: 3, categoryIds: [5]));

        $progress = $coupon->progress(self::basket(self::lamp(1), self::book(4)));
        self::assertSame([1, 3, 2], [$progress?->units, $progress?->neededUnits, $progress?->unitsShort()], 'books are not lamps');
        self::assertNull($progress?->short());
        self::assertTrue($coupon->progress(self::basket(self::lamp(3)))?->holds());
    }

    public function testAnAmountOffEachNeedsOneOfItsItemsWhenTheCartHasNone(): void
    {
        $coupon = self::coupon(new CouponTerms('SHADES', CouponKind::ItemAmount, Money::of('2'), productIds: [30]));

        $progress = $coupon->progress(self::basket(self::lamp(2)));
        self::assertSame([0, 1], [$progress?->units, $progress?->unitsShort()]);
        self::assertSame(0.0, $progress?->nearness());
    }

    public function testFreeShippingFromAnOrderSize(): void
    {
        $coupon = self::coupon(new CouponTerms('SHIP', CouponKind::FreeShipping, Money::zero(), minSubtotal: Money::of('40')));

        self::assertSame('20', (string) $coupon->progress(self::basket(self::lamp(1)))?->short());
        self::assertTrue($coupon->progress(self::basket(self::lamp(2)))?->holds());
    }

    public function testBuyTwoGetOneOfTheSameNeedsThreeOfThem(): void
    {
        $coupon = self::coupon(new CouponTerms('THREE', CouponKind::BuyGet, Money::of('100'), productIds: [10], buyQuantity: 2, getQuantity: 1));

        self::assertSame(2, $coupon->progress(self::basket(self::lamp(1)))?->unitsShort());
        self::assertSame(1, $coupon->progress(self::basket(self::lamp(2), self::book(5)))?->unitsShort(), 'the one given is one of them too');
        self::assertTrue($coupon->progress(self::basket(self::lamp(3)))?->holds());
    }

    public function testBuyTwoGetAnotherNeedsTheOneItGives(): void
    {
        $coupon = self::coupon(new CouponTerms('SHADE', CouponKind::BuyGet, Money::of('50'), productIds: [10], buyQuantity: 2, getQuantity: 1, getProductIds: [30]));

        $one = $coupon->progress(self::basket(self::lamp(1)));
        self::assertSame([1, true], [$one?->unitsShort(), $one?->needsGiven]);
        $two = $coupon->progress(self::basket(self::lamp(2)));
        self::assertSame([0, true, false], [$two?->unitsShort(), $two?->needsGiven, $two?->holds()]);
        self::assertEqualsWithDelta(2 / 3, $two?->nearness() ?? 0, 0.0001);
        self::assertTrue($coupon->progress(self::basket(self::lamp(2), self::shade(1)))?->holds());
    }

    public function testAFreeGiftLeavesTheGiftOutOfTheOrdersSize(): void
    {
        $coupon = self::coupon(new CouponTerms('TOTE', CouponKind::FreeGift, Money::zero(), minSubtotal: Money::of('50'), getQuantity: 1, giftProductId: 30));

        self::assertSame('30', (string) $coupon->progress(self::basket(self::lamp(1), self::shade(1)))?->short(), 'the shade is the gift: 20 of the order counts');
        self::assertSame('10', (string) $coupon->progress(self::basket(self::lamp(2)))?->short());
        self::assertTrue($coupon->progress(self::basket(self::lamp(3)))?->holds());
    }

    public function testNoProgressPastTheLargestOrder(): void
    {
        $coupon = self::coupon(new CouponTerms('SMALL', CouponKind::Percent, Money::of('5'), maxSubtotal: Money::of('30')));

        self::assertNotNull($coupon->progress(self::basket(self::lamp(1))));
        self::assertNull($coupon->progress(self::basket(self::lamp(2))), 'adding items cannot bring the order under it');
    }

    public function testSaleItemsLeftOutDoNotCount(): void
    {
        $coupon = self::coupon(new CouponTerms('FULL', CouponKind::Percent, Money::of('10'), excludeSaleItems: true));

        self::assertSame([0, 1], [$coupon->progress(self::basket(self::lamp(2, onSale: true)))?->units, $coupon->progress(self::basket(self::lamp(2, onSale: true)))?->unitsShort()]);
    }

    public function testTheSmallestOrderIsInTheCartsCurrency(): void
    {
        $coupon = self::coupon(new CouponTerms('BIG', CouponKind::Percent, Money::of('10'), minSubtotal: Money::of('50')));
        $progress = $coupon->progress(new Basket([self::lamp(1)], 'BGN', rate: '2'));

        self::assertSame(['100', '80'], [(string) $progress?->threshold, (string) $progress?->short()]);
    }

    /**
     * @param list<BasketLine> $lines
     */
    #[DataProvider('carts')]
    public function testACartThatHoldsIsNeverRefusedAndOneThatLacksIs(CouponTerms $terms, array $lines): void
    {
        $coupon = self::coupon($terms);
        $basket = self::basket(...$lines);
        $progress = $coupon->progress($basket);
        $refusal = $coupon->refusal($basket, $this->now, 0);

        self::assertSame($progress?->holds() === true, $refusal === null, (string) $refusal);
    }

    /**
     * @return iterable<string, array{CouponTerms, list<BasketLine>}>
     */
    public static function carts(): iterable
    {
        $kinds = [
            'percent' => new CouponTerms('A', CouponKind::Percent, Money::of('10'), minSubtotal: Money::of('40'), categoryIds: [5]),
            'order amount' => new CouponTerms('B', CouponKind::OrderAmount, Money::of('5'), minQuantity: 2, productIds: [10]),
            'item amount' => new CouponTerms('C', CouponKind::ItemAmount, Money::of('1'), excludedProductIds: [20]),
            'free shipping' => new CouponTerms('D', CouponKind::FreeShipping, Money::zero(), minSubtotal: Money::of('30')),
            'buy get' => new CouponTerms('E', CouponKind::BuyGet, Money::of('100'), buyQuantity: 2, getQuantity: 1, productIds: [10]),
            'buy get others' => new CouponTerms('F', CouponKind::BuyGet, Money::of('100'), buyQuantity: 1, getQuantity: 1, productIds: [10], getProductIds: [30]),
            'free gift' => new CouponTerms('G', CouponKind::FreeGift, Money::zero(), minSubtotal: Money::of('40'), getQuantity: 1, giftProductId: 30),
        ];
        $carts = [
            'a lamp' => [self::lamp(1)],
            'two lamps' => [self::lamp(2)],
            'three lamps and a shade' => [self::lamp(3), self::shade(1)],
            'books' => [self::book(4)],
            'a lamp and a shade' => [self::lamp(1), self::shade(1)],
        ];
        foreach ($kinds as $kind => $terms) {
            foreach ($carts as $cart => $lines) {
                yield "{$kind}, {$cart}" => [$terms, $lines];
            }
        }
    }

    public function testOnlyOneTheShopperCouldStillHaveIsObtainable(): void
    {
        $basket = self::basket(self::lamp(1));
        $ok = static fn(CouponTerms $terms, ?Basket $in = null, int $uses = 0, ?Coupon $coupon = null): bool => ($coupon ?? self::coupon($terms))->obtainable($in ?? $basket, new \DateTimeImmutable('2026-10-31 12:00:00'), $uses);

        self::assertTrue($ok(new CouponTerms('ON', CouponKind::Percent, Money::of('10'))));
        self::assertFalse($ok(new CouponTerms('OFF', CouponKind::Percent, Money::of('10'), active: false)), 'switched off');
        self::assertFalse($ok(new CouponTerms('LATER', CouponKind::Percent, Money::of('10'), startsAt: new \DateTimeImmutable('2026-11-01'))), 'not begun');
        self::assertFalse($ok(new CouponTerms('OVER', CouponKind::Percent, Money::of('10'), endsAt: new \DateTimeImmutable('2026-10-01'))), 'ended');
        $usedUp = self::coupon(new CouponTerms('ONCE', CouponKind::Percent, Money::of('10'), usageLimit: 1));
        $usedUp->use();
        self::assertFalse($ok($usedUp->terms(), coupon: $usedUp), 'used up');
        self::assertFalse($ok(new CouponTerms('EACH', CouponKind::Percent, Money::of('10'), perCustomerLimit: 2), uses: 2), 'this customer used it up');
        self::assertTrue($ok(new CouponTerms('EACH', CouponKind::Percent, Money::of('10'), perCustomerLimit: 2), uses: 1));
        self::assertFalse($ok(new CouponTerms('HELLO', CouponKind::Percent, Money::of('10'), firstOrderOnly: true), new Basket([self::lamp(1)], 'EUR', firstOrder: false)), 'one who ordered before');
        self::assertFalse($ok(new CouponTerms('DE', CouponKind::Percent, Money::of('10'), countries: ['DE']), new Basket([self::lamp(1)], 'EUR', 'BG')), 'another country');
        self::assertTrue($ok(new CouponTerms('DE', CouponKind::Percent, Money::of('10'), countries: ['DE']), new Basket([self::lamp(1)], 'EUR')), 'a country not known yet');
        self::assertTrue($ok(new CouponTerms('BIG', CouponKind::Percent, Money::of('10'), minSubtotal: Money::of('500'))), 'what the cart lacks is progress, not a refusal');
    }

    public function testItAppliesToAnItemItIsForAndDoesNotLeaveOut(): void
    {
        $lamp = self::lamp(1);
        self::assertTrue(self::coupon(new CouponTerms('ALL', CouponKind::Percent, Money::of('10')))->appliesTo($lamp));
        self::assertTrue(self::coupon(new CouponTerms('CAT', CouponKind::Percent, Money::of('10'), categoryIds: [5]))->appliesTo($lamp));
        self::assertFalse(self::coupon(new CouponTerms('BOOKS', CouponKind::Percent, Money::of('10'), categoryIds: [6]))->appliesTo($lamp));
        self::assertFalse(self::coupon(new CouponTerms('NOLAMP', CouponKind::Percent, Money::of('10'), excludedProductIds: [10]))->appliesTo($lamp), 'excluded');
        self::assertFalse(self::coupon(new CouponTerms('NOCAT', CouponKind::Percent, Money::of('10'), excludedCategoryIds: [5]))->appliesTo($lamp));
        self::assertFalse(self::coupon(new CouponTerms('FULL', CouponKind::Percent, Money::of('10'), excludeSaleItems: true))->appliesTo(self::lamp(1, onSale: true)), 'on sale');
        $ruled = new BasketLine(10, 1, Money::of('20'), categoryIds: [5], coupons: false);
        self::assertFalse(self::coupon(new CouponTerms('ALL', CouponKind::Percent, Money::of('10')))->appliesTo($ruled), 'a price rule keeps coupons off');
        self::assertTrue(self::coupon(new CouponTerms('STAFF', CouponKind::Percent, Money::of('10'), overridesPriceRules: true))->appliesTo($ruled));
        self::assertFalse(self::coupon(new CouponTerms('TOTE', CouponKind::FreeGift, Money::zero(), getQuantity: 1, giftProductId: 10))->appliesTo($lamp), 'the gift itself');
    }

    public function testShownToShoppersByDefaultWhenAutomatic(): void
    {
        $terms = new CouponTerms('SALE', CouponKind::Percent, Money::of('10'), 'Sale');
        self::assertTrue(new Coupon($terms, true)->isAdvertised());
        self::assertFalse(new Coupon($terms)->isAdvertised(), 'a code is the shop\'s to show');
        self::assertTrue(new Coupon($terms, advertised: true)->isAdvertised());
        $hidden = new Coupon($terms, true, false);
        self::assertFalse($hidden->isAdvertised());
        $hidden->advertise(null);
        self::assertTrue($hidden->isAdvertised(), 'back to the default');
    }

    private static function coupon(CouponTerms $terms): Coupon
    {
        return new Coupon($terms);
    }

    private static function basket(BasketLine ...$lines): Basket
    {
        return new Basket(array_values($lines), 'EUR');
    }

    private static function lamp(int $quantity, bool $onSale = false): BasketLine
    {
        return new BasketLine(10, $quantity, Money::of('20')->times($quantity), $onSale, [5]);
    }

    private static function book(int $quantity): BasketLine
    {
        return new BasketLine(20, $quantity, Money::of('12')->times($quantity), categoryIds: [6]);
    }

    private static function shade(int $quantity): BasketLine
    {
        return new BasketLine(30, $quantity, Money::of('8')->times($quantity));
    }
}
