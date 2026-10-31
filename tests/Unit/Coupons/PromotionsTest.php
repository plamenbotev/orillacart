<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Coupons;

use OrillaCart\Cart\Application\Dto\AddToCart;
use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Checkout\Application\Dto\CheckoutDetails;
use OrillaCart\Checkout\Application\Dto\CouponCode;
use OrillaCart\Checkout\Application\Dto\PlaceOrder;
use OrillaCart\Coupons\Application\Dto\DiscountItem;
use OrillaCart\Coupons\Application\Dto\PromotionView;
use OrillaCart\Coupons\Application\Dto\SaveCoupon;
use OrillaCart\Customers\Application\AddressBook;
use OrillaCart\Ordering\Application\Dto\AddressData;
use OrillaCart\Payment\Application\Dto\SavePaymentMethod;
use OrillaCart\Shared\I18n\Language;
use OrillaCart\Shipping\Application\Dto\SaveCarrier;
use OrillaCart\Shipping\Application\Dto\SaveShippingRate;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use PHPUnit\Framework\TestCase;

/**
 * The promotions a cart and a product are told of: automatic discounts unless hidden, codes the shop shows, and
 * only those the shopper could still get, priced as the cart page prices the cart (the shop is in Bulgaria). A lamp
 * (category 5) is 20, a shade 10.
 */
final class PromotionsTest extends TestCase
{
    private TestShop $shop;
    private int $rate;

    protected function setUp(): void
    {
        $this->shop = new TestShop();
        $this->shop->content->add(10, 'Lamp');
        $this->shop->products->save(10, new SaveProduct('20', weight: '1'));
        $this->shop->content->terms[10] = ['categories' => [5], 'brands' => []];
        $this->shop->content->add(20, 'Shade');
        $this->shop->products->save(20, new SaveProduct('10', weight: '1'));
        $this->rate = $this->shop->carriers->create(new SaveCarrier('Courier', rates: [new SaveShippingRate('Bulgaria', '5', regions: ['BG'])]))->rates[0]->id;
        $this->shop->paymentMethods->update('bank_transfer', new SavePaymentMethod('Bank transfer', true));
    }

    public function testBuyTwoGetOneFreeSaysHowManyMoreThenThatItApplies(): void
    {
        $this->automatic('Lamps: buy 2, get 1 free', 'buy_get', '100', buyQuantity: 2, getQuantity: 1, productIds: [10]);

        $one = $this->only($this->cart([10, 1]));
        self::assertSame([false, 1, 3, 2], [$one->applied, $one->units, $one->neededUnits, $one->unitsShort()]);
        self::assertSame(1, $this->only($this->cart([10, 2]))->unitsShort());

        $three = $this->only($this->cart([10, 3]));
        self::assertSame([true, '20'], [$three->applied, $three->saved], 'what checkout takes off');
    }

    public function testAnOrderSizeIsMeasuredOnTheWholeOrderBeforeDiscounts(): void
    {
        $this->automatic('10% off lamps over 50', 'percent', '10', minSubtotal: '50', categoryIds: [5]);

        $promotion = $this->only($this->cart([10, 1], [20, 1]));
        self::assertSame(['30', '20', '50'], [$promotion->spent, $promotion->short, $promotion->minSubtotal], 'the shade counts towards the order');
        self::assertSame('percent', $promotion->kind);
    }

    public function testAFreeGiftThatHoldsIsApplied(): void
    {
        $this->automatic('A free shade over 30', 'free_gift', '0', minSubtotal: '30', getQuantity: 1, giftProductId: 20);

        self::assertSame('10', $this->only($this->cart([10, 1]))->short);
        $applied = $this->only($this->cart([10, 2]));
        self::assertSame([true, '10'], [$applied->applied, $applied->saved], 'checkout added the shade, for nothing');
    }

    public function testCodesAreHiddenUnlessTheShopShowsThem(): void
    {
        $this->shop->coupons->create(new SaveCoupon('SECRET', 'percent', '10'));
        self::assertSame([], $this->shop->promotionProgress->of($this->cart([10, 1])));

        $this->shop->coupons->create(new SaveCoupon('SUMMER10', 'percent', '10', minSubtotal: '50', advertised: true));
        $promotion = $this->only($this->cart([10, 1]));
        self::assertSame(['SUMMER10', false, '30'], [$promotion->code, $promotion->automatic, $promotion->short]);

        $this->shop->coupons->create(new SaveCoupon('NOW', 'order_amount', '5', advertised: true, combines: true));
        $codes = array_map(static fn(PromotionView $promotion): string => $promotion->code, $this->shop->promotionProgress->of($this->cart([10, 1])));
        self::assertSame(['NOW', 'SUMMER10'], $codes, 'one that holds already, to enter, first');
    }

    public function testAnAutomaticDiscountTheShopHidesIsNotShown(): void
    {
        $this->automatic('Sale', 'percent', '10', minSubtotal: '100', advertised: false);

        self::assertSame([], $this->shop->promotionProgress->of($this->cart([10, 1])));
    }

    public function testACodeInTheCartThatDoesNotHoldIsLeftToTheCart(): void
    {
        $this->shop->coupons->create(new SaveCoupon('BIG', 'percent', '10', minSubtotal: '100', advertised: true));
        $owner = $this->cart([10, 1]);
        $this->shop->carts->useCoupons($this->shop->carts->contents($owner)->cartId ?? 0, ['BIG']);

        self::assertSame([], $this->shop->promotionProgress->of($owner));
    }

    public function testOneThatWouldNotGoWithWhatTheCartGetsIsNotShown(): void
    {
        $this->automatic('5% off everything', 'percent', '5');
        $this->shop->coupons->create(new SaveCoupon('ALONE', 'percent', '20', minSubtotal: '100', advertised: true));
        $this->shop->coupons->create(new SaveCoupon('WITH', 'percent', '10', minSubtotal: '100', advertised: true, combines: true));

        $shown = $this->shop->promotionProgress->of($this->cart([10, 1]));
        self::assertSame([['AUTO', true], ['WITH', false]], array_map(static fn(PromotionView $promotion): array => [$promotion->automatic ? 'AUTO' : $promotion->code, $promotion->applied], $shown), 'a code that does not combine is refused beside an automatic discount');
    }

    public function testAnAutomaticDiscountThatDoesNotCombineIsShownUnlessAnEarlierOneApplies(): void
    {
        $this->automatic('5% off everything', 'percent', '5');
        $this->automatic('10% off over 100', 'percent', '10', minSubtotal: '100');

        self::assertCount(1, $this->shop->promotionProgress->of($this->cart([10, 1])), 'the older one stands alone');
    }

    public function testNotBegunEndedOrUsedUpIsNotShown(): void
    {
        $this->automatic('Later', 'percent', '10', startsAt: '2099-01-01T00:00:00+00:00');
        $this->automatic('Over', 'percent', '10', endsAt: '2020-01-01T00:00:00+00:00');
        $this->automatic('Once', 'percent', '10', minSubtotal: '100', usageLimit: 1);
        $this->shop->couponRecords->find(3)?->use();

        self::assertSame([], $this->shop->promotionProgress->of($this->cart([10, 1])));
    }

    public function testAnotherCountryThanTheShoppersIsNotShown(): void
    {
        $this->automatic('Germany', 'percent', '10', minSubtotal: '100', countries: ['DE']);
        self::assertSame([], $this->shop->promotionProgress->of($this->cart([10, 1])), 'a visitor is the shop\'s country');

        $ada = $this->shop->accounts->add('ada@example.com', 'Ada');
        $this->shop->accounts->saveAddresses($ada, new AddressBook()->remembering(new AddressData('Ada', 'Lovelace', '1 Unter den Linden', 'Berlin', '10117', 'DE', '+49 30 000000'), null));
        self::assertCount(1, $this->shop->promotionProgress->of(new CartOwner($this->cart([10, 1])->token, $ada)), 'a customer is their saved address\'s');
    }

    public function testAFirstOrderDiscountIsForAKnownCustomerWhoNeverOrdered(): void
    {
        $this->automatic('Welcome', 'percent', '10', minSubtotal: '100', firstOrderOnly: true);
        self::assertSame([], $this->shop->promotionProgress->of($this->cart([10, 1])), 'a visitor may have ordered before');

        $ada = $this->shop->accounts->add('ada@example.com', 'Ada');
        self::assertCount(1, $this->shop->promotionProgress->of(new CartOwner($this->cart([10, 1])->token, $ada)));

        $this->placeOrder(new CartOwner($this->cart([10, 1])->token, $ada));
        self::assertSame([], $this->shop->promotionProgress->of(new CartOwner($this->cart([10, 1])->token, $ada)), 'she ordered');
    }

    public function testOneACustomerUsedUpIsNotShownToThem(): void
    {
        $this->shop->coupons->create(new SaveCoupon('ONCE', 'order_amount', '5', perCustomerLimit: 1, advertised: true));
        $ada = $this->shop->accounts->add('ada@example.com', 'Ada');
        self::assertSame([], $this->shop->promotionProgress->of($this->cart([10, 1])), 'a visitor\'s uses are not known');
        $first = new CartOwner($this->cart([10, 1])->token, $ada);
        self::assertCount(1, $this->shop->promotionProgress->of($first));

        $this->shop->checkout->applyCoupon($first, new CouponCode('ONCE'));
        $this->placeOrder($first);
        self::assertSame([], $this->shop->promotionProgress->of(new CartOwner($this->cart([10, 1])->token, $ada)));
    }

    public function testTheNearestComeFirst(): void
    {
        $this->automatic('Over 200', 'percent', '15', minSubtotal: '200', combines: true);
        $this->automatic('Over 40', 'percent', '5', minSubtotal: '40', combines: true);
        $this->automatic('Over 100', 'percent', '10', minSubtotal: '100', combines: true);

        self::assertSame(['Over 40', 'Over 100', 'Over 200'], array_map(static fn(PromotionView $promotion): string => $promotion->description, $this->shop->promotionProgress->of($this->cart([10, 1]))));
    }

    public function testNothingForAnEmptyCart(): void
    {
        $this->automatic('Over 40', 'percent', '5', minSubtotal: '40');

        self::assertSame([], $this->shop->promotionProgress->of(new CartOwner(null)));
    }

    public function testAProductIsToldOfThePromotionsThatTakeSomethingOffIt(): void
    {
        $this->automatic('Lamps: buy 2, get 1 free', 'buy_get', '100', buyQuantity: 2, getQuantity: 1, productIds: [10]);
        $this->automatic('10% off over 50', 'percent', '10', minSubtotal: '50', excludedProductIds: [20]);
        $this->automatic('Welcome', 'percent', '10', firstOrderOnly: true);
        $this->automatic('Tote', 'free_gift', '0', minSubtotal: '30', getQuantity: 1, giftProductId: 20);
        $this->shop->coupons->create(new SaveCoupon('SUMMER10', 'percent', '10', advertised: true));
        $this->shop->coupons->create(new SaveCoupon('SECRET', 'percent', '10'));

        $lamp = new DiscountItem(10, 1, '20', categoryIds: [5]);
        $shade = new DiscountItem(20, 1, '10');
        self::assertSame(['Lamps: buy 2, get 1 free', '10% off over 50', 'Tote', 'SUMMER10'], self::names($this->shop->promotions->forProduct($lamp, 'EUR', country: 'BG')), 'a visitor: not the first order one');
        self::assertSame(['SUMMER10'], self::names($this->shop->promotions->forProduct($shade, 'EUR', country: 'BG')), 'excluded, and the gift itself');

        $ada = $this->shop->accounts->add('ada@example.com', 'Ada');
        self::assertContains('Welcome', self::names($this->shop->promotions->forProduct($lamp, 'EUR', country: 'BG', customerId: $ada)));
        $promotion = $this->shop->promotions->forProduct($lamp, 'BGN', '2', 'BG')[1];
        self::assertSame(['100', 'BGN'], [$promotion->minSubtotal, $promotion->currency], 'in the shopper\'s currency');
    }

    public function testTheShopperReadsTheDescriptionInTheLanguageShown(): void
    {
        $this->shop->contentLanguages->languages = [new Language('en', 'en_US', 'English'), new Language('de', 'de_DE', 'Deutsch')];
        $sale = $this->shop->coupons->create(new SaveCoupon('', 'percent', '10', 'Lamp sale', categoryIds: [5], automatic: true, translations: ['description' => ['de_DE' => 'Lampen-Rabatt']]));
        self::assertSame(['description' => ['de_DE' => 'Lampen-Rabatt']], $this->shop->coupons->coupon($sale->id)->translations);
        self::assertTrue($sale->advertised, 'an automatic discount is shown by default');

        $owner = $this->cart([10, 1]);
        self::assertSame('Lamp sale', $this->only($owner)->description);
        $this->shop->languageOfRequest->locale = 'de_DE';
        self::assertSame('Lampen-Rabatt', $this->only($owner)->description);
        self::assertSame('Lampen-Rabatt', $this->shop->checkout->summary($owner, new CheckoutDetails())->coupons[0]->description, 'on the cart too');

        $kept = $this->shop->coupons->update($sale->id, new SaveCoupon('', 'percent', '10', 'Lamp sale', categoryIds: [5], automatic: true, advertised: false));
        self::assertSame([false, ['description' => ['de_DE' => 'Lampen-Rabatt']]], [$kept->advertised, $kept->translations], 'a save without translations keeps them');
        $this->shop->coupons->delete($sale->id);
        self::assertSame([], $this->shop->contentTranslations->of('coupon', [$sale->id]));
    }

    /**
     * @param list<int>    $productIds
     * @param list<int>    $categoryIds
     * @param list<int>    $excludedProductIds
     * @param list<string> $countries
     */
    private function automatic(string $description, string $kind, string $amount, ?string $minSubtotal = null, array $productIds = [], array $categoryIds = [], array $excludedProductIds = [], array $countries = [], int $buyQuantity = 0, int $getQuantity = 0, ?int $giftProductId = null, bool $firstOrderOnly = false, ?int $usageLimit = null, ?string $startsAt = null, ?string $endsAt = null, bool $combines = false, ?bool $advertised = null): void
    {
        $this->shop->coupons->create(new SaveCoupon('', $kind, $amount, $description, startsAt: $startsAt, endsAt: $endsAt, minSubtotal: $minSubtotal, countries: $countries, productIds: $productIds, categoryIds: $categoryIds, excludedProductIds: $excludedProductIds, combines: $combines, usageLimit: $usageLimit, firstOrderOnly: $firstOrderOnly, automatic: true, buyQuantity: $buyQuantity, getQuantity: $getQuantity, giftProductId: $giftProductId, advertised: $advertised));
    }

    /**
     * @param list<PromotionView> $promotions
     *
     * @return list<string> their descriptions, or codes for those without
     */
    private static function names(array $promotions): array
    {
        return array_map(static fn(PromotionView $promotion): string => $promotion->description !== '' ? $promotion->description : $promotion->code, $promotions);
    }

    private function only(CartOwner $owner): PromotionView
    {
        $promotions = $this->shop->promotionProgress->of($owner);
        self::assertCount(1, $promotions);

        return $promotions[0];
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

    private function placeOrder(CartOwner $owner): void
    {
        $this->shop->checkout->placeOrder($owner, new PlaceOrder('ada@example.com', new AddressData('Ada', 'Lovelace', '1 Vitosha Blvd', 'Sofia', '1000', 'BG', '+359 2 000 0000'), shippingOption: "rate:{$this->rate}", payment: 'bank_transfer'));
    }
}
