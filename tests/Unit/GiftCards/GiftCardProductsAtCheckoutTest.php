<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\GiftCards;

use OrillaCart\Cart\Application\Dto\AddToCart;
use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Checkout\Application\Dto\CheckoutDetails;
use OrillaCart\Checkout\Application\Dto\CouponCode;
use OrillaCart\Checkout\Application\Dto\PlaceOrder;
use OrillaCart\Checkout\Domain\CheckoutRuleViolation;
use OrillaCart\Coupons\Application\Dto\SaveCoupon;
use OrillaCart\GiftCards\Application\Dto\AdjustBalance;
use OrillaCart\GiftCards\Application\Dto\GiftCardCodeInput;
use OrillaCart\GiftCards\Application\Dto\IssueGiftCard;
use OrillaCart\GiftCards\Domain\GiftCardSettings;
use OrillaCart\Ordering\Application\Dto\AddressData;
use OrillaCart\Payment\Application\Dto\SavePaymentMethod;
use OrillaCart\Settings\Domain\StoreSettings;
use OrillaCart\Shared\Domain\Money;
use OrillaCart\Shipping\Application\Dto\SaveCarrier;
use OrillaCart\Shipping\Application\Dto\SaveRange;
use OrillaCart\Shipping\Application\Dto\SaveShippingRate;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use PHPUnit\Framework\TestCase;

/**
 * Gift cards bought are money to spend, not goods (PLAN 438): they count towards no threshold, and the shop has them
 * paid for with money only unless it chooses otherwise.
 */
final class GiftCardProductsAtCheckoutTest extends TestCase
{
    private TestShop $shop;
    private int $paid;
    private int $free;

    protected function setUp(): void
    {
        $this->shop = new TestShop();
        $this->shop->content->add(10, 'Lamp');
        $this->shop->products->save(10, new SaveProduct('40', weight: '1'));
        $this->shop->content->add(20, 'Gift card');
        $this->shop->products->save(20, new SaveProduct('25', 'virtual', taxable: false));
        $this->shop->giftCardSettings->save(new GiftCardSettings([20]));
        $rates = $this->shop->carriers->create(new SaveCarrier('Courier', rates: [
            new SaveShippingRate('Standard', '5', regions: ['BG'], subtotal: new SaveRange('0', '50')),
            new SaveShippingRate('Free from 50', '0', regions: ['BG'], subtotal: new SaveRange('50')),
        ]))->rates;
        [$this->paid, $this->free] = [$rates[0]->id, $rates[1]->id];
        $this->shop->paymentMethods->update('bank_transfer', new SavePaymentMethod('Bank transfer', true));
    }

    public function testAGiftCardDoesNotShipTheRestFree(): void
    {
        $summary = $this->shop->checkout->summary($this->cart([10, 1], [20, 1]), new CheckoutDetails('BG'));

        self::assertSame(["rate:{$this->paid}"], array_map(static fn($option): string => $option->id, $summary->shippingOptions), 'the items are 40: the 25 card does not lift them past 50');
        $lamps = $this->shop->checkout->summary($this->cart([10, 2]), new CheckoutDetails('BG'));
        self::assertSame(["rate:{$this->free}"], array_map(static fn($option): string => $option->id, $lamps->shippingOptions), 'two lamps ship free');
    }

    public function testAGiftCardDoesNotReachTheMinimumOrder(): void
    {
        $settings = $this->shop->settings->load();
        $this->shop->settings->save(new StoreSettings($settings->currency, 2, $settings->weightUnit, $settings->dimensionUnit, 'BG', minimumOrder: Money::of('50')));

        $summary = $this->shop->checkout->summary($this->cart([10, 1], [20, 1]), new CheckoutDetails('BG', shippingOption: "rate:{$this->paid}"));

        self::assertSame(['50', '10'], [$summary->minimumOrder, $summary->minimumShort]);
    }

    public function testAGiftCardDoesNotReachACodesMinimum(): void
    {
        $this->shop->coupons->create(new SaveCoupon('BIG', 'order_amount', '10', minSubtotal: '50'));
        $owner = $this->cart([10, 1], [20, 1]);

        $this->expectExceptionMessage('This code needs an order of at least 50 EUR.');
        $this->shop->checkout->applyCoupon($owner, new CouponCode('BIG'));
    }

    public function testGiftCardsAndStoreCreditDoNotPayForAGiftCard(): void
    {
        $issued = $this->shop->giftCards->issue(new IssueGiftCard('30'), 1);
        $owner = $this->cart([20, 1]);

        $summary = $this->shop->checkout->applyGiftCard($owner, new GiftCardCodeInput($issued->code));
        self::assertSame(['0', 'Gift cards are bought with money only: not with gift cards or store credit.'], [$summary->giftCardTotal, (string) $summary->giftCards[0]->refused]);
        try {
            $this->shop->checkout->placeOrder($owner, $this->order());
            self::fail('a card for a card');
        } catch (CheckoutRuleViolation $e) {
            self::assertSame('orillacart_gift_card_refused', $e->errorCode);
        }

        $ada = $this->shop->accounts->add('ada@example.com', 'Ada');
        $this->shop->giftCards->adjustStoreCredit($ada, new AdjustBalance('20', 'Sorry'), 1);
        $owner = new CartOwner($this->shop->carts->add(new CartOwner(null, $ada), new AddToCart(20))->newToken, $ada);
        $summary = $this->shop->checkout->useStoreCredit($owner, true);
        self::assertSame(['0', '0', false], [$summary->storeCredit, $summary->giftCardTotal, $summary->usingStoreCredit]);
    }

    public function testTheShopMayLetCardsPayForCards(): void
    {
        $this->shop->giftCardSettings->save(new GiftCardSettings([20], paidWithMoneyOnly: false));
        $issued = $this->shop->giftCards->issue(new IssueGiftCard('30'), 1);
        $owner = $this->cart([20, 1]);

        $summary = $this->shop->checkout->applyGiftCard($owner, new GiftCardCodeInput($issued->code));

        self::assertSame(['25', null], [$summary->giftCardTotal, $summary->giftCards[0]->refused]);
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
        return new PlaceOrder('ada@example.com', new AddressData('Ada', 'Lovelace', '1 Vitosha Blvd', 'Sofia', '1000', 'BG', '+359 2 000 0000'), payment: 'bank_transfer');
    }
}
