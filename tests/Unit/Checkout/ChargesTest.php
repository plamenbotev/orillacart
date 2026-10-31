<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Checkout;

use OrillaCart\Cart\Application\Dto\AddToCart;
use OrillaCart\Cart\Application\Dto\CartLineView;
use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Cart\Application\Dto\ChangeQuantity;
use OrillaCart\Catalog\Application\Dto\PriceTierData;
use OrillaCart\Catalog\Application\Dto\SavePriceRule;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Checkout\Application\Dto\AddOrderCharge;
use OrillaCart\Checkout\Application\Dto\CheckoutDetails;
use OrillaCart\Checkout\Application\Dto\PlaceOrder;
use OrillaCart\Checkout\Domain\CheckoutRuleViolation;
use OrillaCart\Coupons\Application\Dto\SaveCoupon;
use OrillaCart\Extension\Cart\CartLineType;
use OrillaCart\Extension\Cart\CheckedCart;
use OrillaCart\Extension\Cart\CheckedLine;
use OrillaCart\Extension\Cart\KeptCharge;
use OrillaCart\Extension\Checkout\Fee;
use OrillaCart\Extension\Checkout\FeeQuery;
use OrillaCart\Extension\Checkout\OrderFee;
use OrillaCart\Ordering\Application\Dto\AddressData;
use OrillaCart\Ordering\Application\Dto\OrderLineView;
use OrillaCart\Ordering\Application\Dto\RefundLine;
use OrillaCart\Payment\Application\Dto\MakeRefund;
use OrillaCart\Payment\Application\Dto\SavePaymentMethod;
use OrillaCart\Shipping\Application\Dto\SaveCarrier;
use OrillaCart\Shipping\Application\Dto\SaveShippingRate;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use PHPUnit\Framework\TestCase;

/**
 * Prices of their own with no product: a deposit an add-on keeps in the cart for each bottle (0.25), the shop's
 * gift wrapping on an order on hold, and an add-on's fee shown as a line of the cart. A bottle is 3.
 */
final class ChargesTest extends TestCase
{
    private TestShop $shop;
    private int $rate;

    protected function setUp(): void
    {
        $this->shop = new TestShop();
        $this->shop->content->add(10, 'Bottle');
        $this->shop->products->save(10, new SaveProduct('3', weight: '1', stockMode: 'always_available'));
        $this->shop->paymentMethods->update('bank_transfer', new SavePaymentMethod('Bank transfer', true));
        $this->rate = $this->shop->carriers->create(new SaveCarrier('Post', rates: [new SaveShippingRate('Anywhere', '5')]))->rates[0]->id;
    }

    public function testAnAddOnsChargeIsALineOfTheCartWithNoProduct(): void
    {
        $this->deposit();
        $owner = $this->cart(6);

        $lines = $this->shop->carts->cart($owner)->cart->lines;
        self::assertSame([['product', 10, 6, '18'], ['charge', 0, 6, '1.5']], array_map(static fn(CartLineView $line): array => [$line->type, $line->productId, $line->quantity, $line->total], $lines));
        self::assertSame(['Bottle deposit', false, false], [$lines[1]->name, $lines[1]->removable, $lines[1]->quantityChangeable]);
        self::assertSame('19.5', $this->shop->carts->cart($owner)->cart->subtotal);

        $this->shop->carts->changeQuantity($owner, $lines[0]->id, new ChangeQuantity(2));
        self::assertSame(2, $this->shop->carts->cart($owner)->cart->lines[1]->quantity, 'in step with the bottles');
    }

    public function testCheckoutChargesItAndTheOrderKeepsItAsACharge(): void
    {
        $this->deposit();
        $owner = $this->cart(6);

        $summary = $this->shop->checkout->summary($owner, new CheckoutDetails('BG', shippingOption: "rate:{$this->rate}"));
        self::assertSame([['Bottle deposit', '1.5', 6, true]], array_map(static fn($fee): array => [$fee->label, $fee->amount, $fee->quantity, $fee->charge], $summary->fees));
        self::assertSame('24.5', $summary->total, '18 + 1.50 + 5 shipping');

        $order = $this->shop->orders->order($this->place($owner));
        $charges = array_values(array_filter($order->charges, static fn(OrderLineView $line): bool => $line->type === 'charge'));
        self::assertSame([['Bottle deposit', 6, '0.25', '1.5', 'add_on:bottle_deposit']], array_map(static fn(OrderLineView $line): array => [$line->name, $line->quantity, $line->unitPrice, $line->total, $line->charge['source'] ?? null], $charges));
        self::assertSame([['Bottle deposit × 6', '1.5']], array_map(static fn($fee): array => [$fee->label, $fee->amount], $order->fees), 'listed with the fees');
        self::assertSame('24.5', $order->total);
    }

    public function testAChargeIsRefundedByQuantity(): void
    {
        $this->deposit();
        $number = $this->place($this->cart(6));
        $this->shop->orders->markPaid($number, null);
        $order = $this->shop->orders->order($number);
        $deposit = array_values(array_filter($order->charges, static fn(OrderLineView $line): bool => $line->type === 'charge'))[0];

        $this->shop->refunds->refund($number, new MakeRefund('6.5', [new RefundLine(0, 2), new RefundLine($deposit->position, 2)]), 7);

        $refunded = array_values(array_filter($this->shop->orders->order($number)->charges, static fn(OrderLineView $line): bool => $line->type === 'charge'))[0];
        self::assertSame(2, $refunded->refundedQuantity, 'two bottles back, and their deposits');
    }

    public function testAReturnOfBottlesTakesBackTheirDeposits(): void
    {
        $this->shop->settings->save(new \OrillaCart\Settings\Domain\StoreSettings(new \OrillaCart\Shared\Domain\Currency('EUR'), 2, \OrillaCart\Settings\Domain\WeightUnit::Kilogram, \OrillaCart\Settings\Domain\DimensionUnit::Centimetre, 'BG', returnDays: 30));
        $this->deposit();
        $number = $this->place($this->cart(6));
        $this->shop->orders->markPaid($number, null);
        $this->shop->orders->changeStatus($number, new \OrillaCart\Ordering\Application\Dto\ChangeOrderStatus('shipped'), 7);
        $receipt = $this->shop->receipts->issue($number);

        $return = $this->shop->returns->request($receipt, new \OrillaCart\Returns\Application\Dto\RequestReturn([new \OrillaCart\Returns\Application\Dto\ReturnItem(0, 2)], 'no_longer_needed'));
        self::assertSame([['Bottle', 2], ['Bottle deposit', 2]], array_map(static fn($item): array => [$item->name, $item->quantity], $return->items));
        self::assertSame('6.5', $return->amount, 'two bottles at 3, and their deposits at 0.25');

        $more = $this->shop->returns->request($receipt, new \OrillaCart\Returns\Application\Dto\RequestReturn([new \OrillaCart\Returns\Application\Dto\ReturnItem(0, 1)], 'no_longer_needed'));
        self::assertSame([['Bottle', 1], ['Bottle deposit', 1]], array_map(static fn($item): array => [$item->name, $item->quantity], $more->items), 'counted with the return before');
    }

    public function testAChargesShareIsWholeAndNeverMoreThanItHolds(): void
    {
        self::assertSame([2, 1, 4, 1], [\OrillaCart\Ordering\Domain\Charge::share(2, 6, 6), \OrillaCart\Ordering\Domain\Charge::share(3, 6, 2), \OrillaCart\Ordering\Domain\Charge::share(9, 6, 4), \OrillaCart\Ordering\Domain\Charge::share(5, 6, 2)]);
    }

    public function testTheShopAddsAndTakesOffACharge(): void
    {
        $number = $this->place($this->cart(2));

        $order = $this->shop->editing->addCharge($number, new AddOrderCharge('Gift wrapping', '3', 2, false), 7);
        $wrapping = array_values(array_filter($order->charges, static fn(OrderLineView $line): bool => $line->type === 'charge'))[0];
        self::assertSame(['Gift wrapping', 2, '6', 'shop'], [$wrapping->name, $wrapping->quantity, $wrapping->total, $wrapping->charge['source'] ?? null]);
        self::assertSame('17', $order->total, '6 bottles + 6 wrapping + 5 shipping');

        $kept = $this->shop->editing->adjust($number, new \OrillaCart\Checkout\Application\Dto\AdjustOrderPrice('-1', 'Sorry'), 7);
        self::assertSame('16', $kept->total, 'a change to the price keeps the charge');

        $order = $this->shop->editing->removeCharge($number, $wrapping->position, 7);
        self::assertSame([], array_values(array_filter($order->charges, static fn(OrderLineView $line): bool => $line->type === 'charge')));
        self::assertSame('10', $order->total);
    }

    public function testAnAddOnsChargeIsNotTheShopsToTakeOff(): void
    {
        $this->deposit();
        $order = $this->shop->orders->order($this->place($this->cart(2)));
        $deposit = array_values(array_filter($order->charges, static fn(OrderLineView $line): bool => $line->type === 'charge'))[0];

        $this->expectException(CheckoutRuleViolation::class);
        $this->shop->editing->removeCharge($order->number, $deposit->position, 7);
    }

    public function testAnAddOnsFeeCanShowAsALineOfTheCart(): void
    {
        $this->shop->extensions->fees = [new class implements OrderFee {
            public function id(): string
            {
                return 'insurance';
            }

            public function fee(FeeQuery $query): Fee
            {
                return new Fee('Insurance', '2', asLine: true);
            }
        }];

        $summary = $this->shop->checkout->summary($this->cart(1), new CheckoutDetails('BG', shippingOption: "rate:{$this->rate}"));
        self::assertSame([['Insurance', true, false]], array_map(static fn($fee): array => [$fee->label, $fee->asLine, $fee->charge], $summary->fees));
    }

    public function testFreeItemsDoNotCountTowardsPricesByQuantity(): void
    {
        $this->shop->priceRules->create(new SavePriceRule('Three or more', [new PriceTierData(3, 'fixed_price', '2')], productIds: [10]));
        $this->shop->coupons->create(new SaveCoupon('', 'buy_get', '100', 'Bottles: buy 2, get 1 free', productIds: [10], automatic: true, buyQuantity: 2, getQuantity: 1));
        $owner = $this->cart(2);

        $lines = $this->shop->carts->cart($owner)->cart->lines;
        self::assertSame([['product', '3'], ['given', '3']], array_map(static fn(CartLineView $line): array => [$line->type, $line->unitPrice], $lines), 'two bought: not three or more');
        $summary = $this->shop->checkout->summary($owner, new CheckoutDetails('BG', shippingOption: "rate:{$this->rate}"));
        self::assertSame(['9', '3'], [$summary->subtotal, $summary->discount], 'three at 3, the given one free: 6 to pay');
    }

    private function deposit(): void
    {
        $this->shop->extensions->cartLineTypes = [new class implements CartLineType {
            public function id(): string
            {
                return 'bottle_deposit';
            }

            public function label(): string
            {
                return 'Deposit';
            }

            public function removable(): bool
            {
                return false;
            }

            public function lines(CheckedCart $cart): array
            {
                $bottles = array_sum(array_map(static fn(CheckedLine $line): int => $line->productId === 10 ? $line->quantity : 0, $cart->lines));

                return $bottles === 0 ? [] : [new KeptCharge('Bottle deposit', '0.25', $bottles, false, forProductIds: [10])];
            }
        }];
    }

    private function cart(int $bottles): CartOwner
    {
        return new CartOwner($this->shop->carts->add(new CartOwner(null), new AddToCart(10, quantity: $bottles))->newToken);
    }

    private function place(CartOwner $owner): int
    {
        return $this->shop->checkout->placeOrder($owner, new PlaceOrder('ada@example.com', new AddressData('Ada', 'Lovelace', '1 Vitosha Blvd', 'Sofia', '1000', 'BG', '+359 2 000 0000'), shippingOption: "rate:{$this->rate}", payment: 'bank_transfer'))->number;
    }
}
