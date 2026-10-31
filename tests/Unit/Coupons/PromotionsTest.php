<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Coupons;

use OrillaCart\Cart\Application\Dto\AddToCart;
use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Catalog\Application\Dto\PriceTierData;
use OrillaCart\Catalog\Application\Dto\SaveAttribute;
use OrillaCart\Catalog\Application\Dto\SaveOption;
use OrillaCart\Catalog\Application\Dto\SavePriceRule;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Catalog\Domain\PriceTier;
use OrillaCart\Checkout\Application\Dto\CheckoutDetails;
use OrillaCart\Checkout\Application\Dto\CouponCode;
use OrillaCart\Checkout\Application\Dto\PlaceOrder;
use OrillaCart\Coupons\Application\Dto\DiscountItem;
use OrillaCart\Coupons\Application\Dto\PromotionView;
use OrillaCart\Coupons\Application\Dto\SaveCoupon;
use OrillaCart\Customers\Application\AddressBook;
use OrillaCart\Customers\Application\Dto\SaveCustomerGroup;
use OrillaCart\Customers\Application\Dto\SetCustomerGroups;
use OrillaCart\Ordering\Application\Dto\AddressData;
use OrillaCart\Payment\Application\Dto\SavePaymentMethod;
use OrillaCart\Shared\I18n\Language;
use OrillaCart\Shipping\Application\Dto\SaveCarrier;
use OrillaCart\Shipping\Application\Dto\SaveShippingRate;
use OrillaCart\Tax\Application\Dto\SaveTaxGroup;
use OrillaCart\Tax\Application\Dto\SaveTaxRate;
use OrillaCart\Tax\Domain\TaxBasis;
use OrillaCart\Tax\Domain\TaxSettings;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use PHPUnit\Framework\TestCase;

/**
 * The promotions a cart and a product are told of: automatic discounts unless hidden, codes the shop shows, and
 * only those the shopper could still get, and the lower price from more of a product by the shop's price rules;
 * priced as the cart page prices the cart (the shop is in Bulgaria). A lamp
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
        self::assertSame([false, 1, 2, 1], [$one->applied, $one->units, $one->neededUnits, $one->unitsShort()]);

        $two = $this->only($this->cart([10, 2]));
        self::assertSame([true, '20'], [$two->applied, $two->saved], 'the shop adds the third, free');
        self::assertSame([true, '20'], [$this->only($this->cart([10, 3]))->applied, $this->only($this->cart([10, 3]))->saved]);
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

    public function testAQuantityTierSaysHowManyMoreForALowerPriceEach(): void
    {
        $this->shop->priceRules->create(new SavePriceRule('Bulk lamps', [new PriceTierData(3, PriceTier::FIXED_PRICE, '18'), new PriceTierData(5, PriceTier::PERCENT, '20')], productIds: [10]));

        $one = $this->only($this->cart([10, 1], [20, 1]));
        self::assertSame([PromotionView::PRICE_TIER, '', true, [10], false], [$one->kind, $one->code, $one->automatic, $one->productIds, $one->applied], 'the lamp only: the shade has no tiers');
        self::assertSame(['18', 'EUR', 1, 3, 2], [$one->amount, $one->currency, $one->units, $one->neededUnits, $one->unitsShort()]);
        self::assertEqualsWithDelta(1 / 3, $one->nearness, 0.0001);

        self::assertSame(['16', 5], [($three = $this->only($this->cart([10, 3])))->amount, $three->neededUnits], 'then the next tier');
        self::assertSame([], $this->shop->promotionProgress->of($this->cart([10, 5])), 'the lowest price already');
    }

    public function testATierNoCheaperThanThePriceNowIsSkipped(): void
    {
        $this->shop->priceRules->create(new SavePriceRule('Lamps for less', [new PriceTierData(1, PriceTier::FIXED_PRICE, '15')], productIds: [10]));
        $this->shop->priceRules->create(new SavePriceRule('Bulk', [new PriceTierData(3, PriceTier::FIXED_PRICE, '17'), new PriceTierData(6, PriceTier::FIXED_PRICE, '12')]));

        $promotion = $this->only($this->cart([10, 1]));
        self::assertSame(['12', 6], [$promotion->amount, $promotion->neededUnits], 'at 3 it would cost 17, more than the 15 it costs now');
    }

    public function testATierIsForTheShoppersCustomerGroups(): void
    {
        $trade = $this->shop->customerGroups->create(new SaveCustomerGroup('Trade'))->id;
        $ada = $this->shop->accounts->add('ada@example.com', 'Ada');
        $this->shop->customerGroups->setGroupsOf($ada, new SetCustomerGroups([$trade]));
        $this->shop->priceRules->create(new SavePriceRule('Trade bulk', [new PriceTierData(2, PriceTier::AMOUNT_OFF, '5')], [$trade]));

        self::assertSame([], $this->shop->promotionProgress->of($this->cart([10, 1])), 'a visitor is in no group');
        self::assertSame('15', $this->only(new CartOwner($this->cart([10, 1])->token, $ada))->amount);
    }

    public function testEveryLineOfTheProductCountsTogether(): void
    {
        $this->shop->content->add(30, 'Mug');
        $mug = $this->shop->products->save(30, new SaveProduct('10', weight: '1', attributes: [new SaveAttribute('Colour', [new SaveOption('White'), new SaveOption('Black'), new SaveOption('Gold', '5')])]));
        [$white, $black, $gold] = array_map(static fn($option): int => $option->id, $mug->attributes[0]->options);
        $this->shop->priceRules->create(new SavePriceRule('Mugs by the box', [new PriceTierData(4, PriceTier::AMOUNT_OFF, '2')], productIds: [30]));

        $owner = $this->mugs([$white, 1], [$black, 2]);
        $promotion = $this->only($owner);
        self::assertSame([3, 4, 1, '8'], [$promotion->units, $promotion->neededUnits, $promotion->unitsShort(), $promotion->amount], 'one more of either for 8 each');

        $this->shop->carts->add($owner, new AddToCart(30, [$gold]));
        self::assertSame([], $this->shop->promotionProgress->of($owner), 'four together: the lower price already');
        self::assertSame(['', 4], [($mixed = $this->only($this->mugs([$white, 1], [$gold, 1], [$black, 1])))->amount, $mixed->neededUnits], 'the lines would cost 8 and 13: no one price to name');
    }

    public function testATierIsAmongTheOffersByNearness(): void
    {
        $this->automatic('Over 40', 'percent', '5', minSubtotal: '40', combines: true);
        $this->automatic('Over 100', 'percent', '10', minSubtotal: '100', combines: true);
        $this->shop->priceRules->create(new SavePriceRule('Bulk', [new PriceTierData(3, PriceTier::PERCENT, '10')], productIds: [10]));

        $kinds = array_map(static fn(PromotionView $promotion): string => $promotion->description !== '' ? $promotion->description : $promotion->kind, $this->shop->promotionProgress->of($this->cart([10, 1])));
        self::assertSame(['Over 40', PromotionView::PRICE_TIER, 'Over 100'], $kinds, 'halfway, a third of the way, a fifth');
    }

    public function testATiersPriceIsShownAsTheCartShowsPrices(): void
    {
        $vat = $this->shop->taxGroups->create(new SaveTaxGroup('Standard'))->id;
        $this->shop->taxGroups->addRate($vat, new SaveTaxRate('BG', null, '20', 'VAT'));
        $this->shop->taxSettings->save(new TaxSettings(TaxBasis::ShippingAddress, $vat, false, shownInCart: TaxSettings::WITH_TAX));
        $this->shop->priceRules->create(new SavePriceRule('Bulk', [new PriceTierData(3, PriceTier::FIXED_PRICE, '18')], productIds: [10]));

        self::assertSame('21.6', $this->only($this->cart([10, 1]))->amount, '18 and the 20 % VAT of the shop\'s country, which a visitor\'s cart is priced at');
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

    /**
     * @param array{int, int} ...$lines the mug's colour, and how many
     */
    private function mugs(array ...$lines): CartOwner
    {
        $owner = new CartOwner(null);
        foreach ($lines as [$colour, $quantity]) {
            $owner = new CartOwner($this->shop->carts->add($owner, new AddToCart(30, [$colour], $quantity))->newToken ?? $owner->token);
        }

        return $owner;
    }

    private function placeOrder(CartOwner $owner): void
    {
        $this->shop->checkout->placeOrder($owner, new PlaceOrder('ada@example.com', new AddressData('Ada', 'Lovelace', '1 Vitosha Blvd', 'Sofia', '1000', 'BG', '+359 2 000 0000'), shippingOption: "rate:{$this->rate}", payment: 'bank_transfer'));
    }
}
