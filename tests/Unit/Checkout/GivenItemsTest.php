<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Checkout;

use OrillaCart\Cart\Application\Dto\AddToCart;
use OrillaCart\Cart\Application\Dto\CartLineView;
use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Cart\Application\Dto\ChangeQuantity;
use OrillaCart\Cart\Domain\CartRuleViolation;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Checkout\Application\Dto\PlaceOrder;
use OrillaCart\Coupons\Application\Dto\SaveCoupon;
use OrillaCart\Ordering\Application\Dto\AddressData;
use OrillaCart\Ordering\Application\Dto\ChangeOrderStatus;
use OrillaCart\Payment\Application\Dto\SavePaymentMethod;
use OrillaCart\Returns\Application\Dto\RequestReturn;
use OrillaCart\Returns\Application\Dto\ReturnItem;
use OrillaCart\Returns\Domain\ReturnRuleViolation;
use OrillaCart\Settings\Domain\DimensionUnit;
use OrillaCart\Settings\Domain\FreeItemsOnReturn;
use OrillaCart\Settings\Domain\StoreSettings;
use OrillaCart\Settings\Domain\WeightUnit;
use OrillaCart\Shared\Domain\Currency;
use OrillaCart\Shipping\Application\Dto\SaveCarrier;
use OrillaCart\Shipping\Application\Dto\SaveShippingRate;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use PHPUnit\Framework\TestCase;

/**
 * The items discounts give are lines of the cart of their own: added and kept in step as the cart changes, the
 * shopper's to remove and have back, sold as items given, and what becomes of them when what they came with goes
 * back is the shop's choice. A lamp is 30.
 */
final class GivenItemsTest extends TestCase
{
    private TestShop $shop;
    private int $rate;

    protected function setUp(): void
    {
        $this->shop = new TestShop();
        $this->settings(FreeItemsOnReturn::Charged);
        $this->shop->content->add(10, 'Lamp');
        $this->shop->products->save(10, new SaveProduct('30', weight: '2', stockMode: 'always_available'));
        $this->shop->content->add(20, 'Shade');
        $this->shop->products->save(20, new SaveProduct('8', weight: '1', stockMode: 'always_available'));
        $this->shop->paymentMethods->update('bank_transfer', new SavePaymentMethod('Bank transfer', true));
        $this->rate = $this->shop->carriers->create(new SaveCarrier('Post', rates: [new SaveShippingRate('Anywhere', '5')]))->rates[0]->id;
    }

    public function testTheItemGivenIsALineOfItsOwnKeptInStepWithTheCart(): void
    {
        $this->lampOffer();
        $owner = $this->cart(2);

        $lines = $this->lines($owner);
        self::assertSame([['product', 2, true], ['given', 1, false]], array_map(static fn(CartLineView $line): array => [$line->type, $line->quantity, $line->quantityChangeable], $lines));
        self::assertSame([true, 'Lamps: buy 2, get 1 free', '0'], [$lines[1]->givenBy !== null, $lines[1]->givenLabel, $lines[1]->total], 'free in the cart');

        $this->shop->carts->changeQuantity($owner, $lines[0]->id, new ChangeQuantity(5));
        self::assertSame([5, 1], array_map(static fn(CartLineView $line): int => $line->quantity, $this->lines($owner)), 'five: a sixth given');
        $this->shop->carts->changeQuantity($owner, $lines[0]->id, new ChangeQuantity(1));
        self::assertCount(1, $this->lines($owner), 'one: none given');
    }

    public function testHowManyAreGivenIsTheOffersNotTheShoppers(): void
    {
        $this->lampOffer();
        $owner = $this->cart(2);

        $this->expectException(CartRuleViolation::class);
        $this->shop->carts->changeQuantity($owner, $this->lines($owner)[1]->id, new ChangeQuantity(3));
    }

    public function testAShopperWhoRemovesTheItemGivenIsNotGivenItAgainUntilTheyAsk(): void
    {
        $this->lampOffer();
        $owner = $this->cart(2);

        $this->shop->carts->remove($owner, $this->lines($owner)[1]->id);
        self::assertCount(1, $this->lines($owner));
        $this->shop->carts->changeQuantity($owner, $this->lines($owner)[0]->id, new ChangeQuantity(5));
        self::assertCount(1, $this->lines($owner), 'not given again as the cart changes');
        $declined = array_values(array_filter($this->shop->promotionProgress->of($owner), static fn($promotion): bool => $promotion->declined));
        self::assertCount(1, $declined, 'the promotion block offers it back');

        $this->shop->carts->restoreGiven($owner, $declined[0]->code);
        self::assertSame([5, 1], array_map(static fn(CartLineView $line): int => $line->quantity, $this->lines($owner)));
    }

    public function testTheShopCanLeaveTheItemsToTheShopper(): void
    {
        $this->shop->coupons->create(new SaveCoupon('', 'buy_get', '100', 'Lamps: buy 2, get 1 free', productIds: [10], automatic: true, buyQuantity: 2, getQuantity: 1, addsItems: false));

        self::assertCount(1, $this->lines($this->cart(2)), 'nothing added');
        $summary = $this->shop->checkout->summary($this->cart(3), new \OrillaCart\Checkout\Application\Dto\CheckoutDetails());
        self::assertSame('30', $summary->discount, 'the third the shopper added is free');
    }

    public function testAFreeGiftIsALineGivenToo(): void
    {
        $this->shop->coupons->create(new SaveCoupon('', 'free_gift', '0', 'A shade with a lamp', automatic: true, getQuantity: 1, giftProductId: 20));

        $lines = $this->lines($this->cart(1));
        self::assertSame([[10, 'product'], [20, 'given']], array_map(static fn(CartLineView $line): array => [$line->productId, $line->type], $lines));
    }

    public function testTheOrderKeepsTheItemGivenAndTheDiscountThatGaveIt(): void
    {
        $this->lampOffer();
        $order = $this->shop->orders->order($this->place($this->cart(2)));

        self::assertSame([['item', 'item', null], ['given', 'item', 'Lamps: buy 2, get 1 free']], array_map(static fn($line): array => [$line->type, $line->kind, $line->givenBy['description'] ?? null], $order->lines));
        self::assertSame(['90', '30', '30'], [$order->subtotal, $order->lines[1]->total, $order->lines[1]->discount], 'the third free: in the subtotal at its price, taken off as discount');
    }

    public function testAFreeItemNoLongerEarnedIsChargedFromTheRefundByDefault(): void
    {
        $this->lampOffer();
        $receipt = $this->shippedOrder();

        $return = $this->shop->returns->request($receipt, new RequestReturn([new ReturnItem(0, 1)], 'no_longer_needed'));

        self::assertSame('0', $return->amount, 'two lamps kept, one of them no longer free: the lamp sent back pays for it');
    }

    public function testAFreeItemNoLongerEarnedComesBackWhenTheShopSaysSo(): void
    {
        $this->settings(FreeItemsOnReturn::ComeBack);
        $this->lampOffer();
        $receipt = $this->shippedOrder();

        try {
            $this->shop->returns->request($receipt, new RequestReturn([new ReturnItem(0, 1)], 'no_longer_needed'));
            self::fail('The free lamp has to come back too.');
        } catch (ReturnRuleViolation $e) {
            self::assertSame('orillacart_return_free_items', $e->errorCode);
        }
        $return = $this->shop->returns->request($receipt, new RequestReturn([new ReturnItem(0, 1), new ReturnItem(1, 1)], 'no_longer_needed'));
        self::assertSame('30', $return->amount);
    }

    public function testAFreeItemCanStayFree(): void
    {
        $this->settings(FreeItemsOnReturn::StayFree);
        $this->lampOffer();

        self::assertSame('30', $this->shop->returns->request($this->shippedOrder(), new RequestReturn([new ReturnItem(0, 1)], 'no_longer_needed'))->amount);
    }

    private function settings(FreeItemsOnReturn $freeItems): void
    {
        $this->shop->settings->save(new StoreSettings(new Currency('EUR'), 2, WeightUnit::Kilogram, DimensionUnit::Centimetre, 'BG', returnDays: 30, freeItemsOnReturn: $freeItems));
    }

    private function lampOffer(): void
    {
        $this->shop->coupons->create(new SaveCoupon('', 'buy_get', '100', 'Lamps: buy 2, get 1 free', productIds: [10], automatic: true, buyQuantity: 2, getQuantity: 1));
    }

    private function cart(int $lamps): CartOwner
    {
        return new CartOwner($this->shop->carts->add(new CartOwner(null), new AddToCart(10, quantity: $lamps))->newToken);
    }

    /** @return list<CartLineView> */
    private function lines(CartOwner $owner): array
    {
        return $this->shop->carts->cart($owner)->cart->lines;
    }

    private function place(CartOwner $owner): int
    {
        return $this->shop->checkout->placeOrder($owner, new PlaceOrder('ada@example.com', new AddressData('Ada', 'Lovelace', '1 Vitosha Blvd', 'Sofia', '1000', 'BG', '+359 2 000 0000'), shippingOption: "rate:{$this->rate}", payment: 'bank_transfer'))->number;
    }

    private function shippedOrder(): string
    {
        $order = $this->place($this->cart(2));
        $this->shop->orders->markPaid($order, null);
        $this->shop->orders->changeStatus($order, new ChangeOrderStatus('shipped'), 7);

        return $this->shop->receipts->issue($order);
    }
}
