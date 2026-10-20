<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Returns;

use OrillaCart\Cart\Application\Dto\AddToCart;
use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Checkout\Application\Dto\PlaceOrder;
use OrillaCart\Ordering\Application\Dto\AddressData;
use OrillaCart\Ordering\Application\Dto\ChangeOrderStatus;
use OrillaCart\Payment\Application\Dto\SavePaymentMethod;
use OrillaCart\Returns\Application\Dto\AnswerReturn;
use OrillaCart\Returns\Application\Dto\RefundReturn;
use OrillaCart\Returns\Application\Dto\RequestReturn;
use OrillaCart\Returns\Application\Dto\ReturnedItemView;
use OrillaCart\Returns\Application\Dto\ReturnItem;
use OrillaCart\Returns\Domain\ReturnRuleViolation;
use OrillaCart\Settings\Domain\DimensionUnit;
use OrillaCart\Settings\Domain\StoreSettings;
use OrillaCart\Settings\Domain\WeightUnit;
use OrillaCart\Shared\Domain\Currency;
use OrillaCart\Shipping\Application\Dto\SaveCarrier;
use OrillaCart\Shipping\Application\Dto\SaveShippingRate;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use PHPUnit\Framework\TestCase;

/**
 * A customer sends items back within the shop's return window; the shop approves, receives and refunds them.
 */
final class ReturnsTest extends TestCase
{
    private TestShop $shop;
    private int $order;
    private string $receipt;

    protected function setUp(): void
    {
        $this->shop = new TestShop();
        $this->shop->settings->save(new StoreSettings(new Currency('EUR'), 2, WeightUnit::Kilogram, DimensionUnit::Centimetre, 'BG', returnDays: 30));
        $this->shop->content->add(10, 'Lamp');
        $this->shop->products->save(10, new SaveProduct('30', weight: '2', stockMode: 'always_available'));
        $this->shop->paymentMethods->update('bank_transfer', new SavePaymentMethod('Bank transfer', true));
        $rate = $this->shop->carriers->create(new SaveCarrier('Post', rates: [new SaveShippingRate('Anywhere', '5')]))->rates[0]->id;
        $owner = new CartOwner($this->shop->carts->add(new CartOwner(null), new AddToCart(10, quantity: 2))->newToken);
        $this->order = $this->shop->checkout->placeOrder($owner, new PlaceOrder('ada@example.com', new AddressData('Ada', 'Lovelace', '1 Vitosha Blvd', 'Sofia', '1000', 'BG', '+359 2 000 0000'), shippingOption: "rate:{$rate}", payment: 'bank_transfer'))->number;
        $this->shop->orders->markPaid($this->order, null);
        $this->receipt = $this->shop->receipts->issue($this->order);
    }

    public function testAnOrderIsReturnedOnlyOnceItLeftAndWithinTheWindow(): void
    {
        self::assertFalse($this->shop->returns->forReceipt($this->receipt)->accepting, 'it has not left');

        $this->shop->orders->changeStatus($this->order, new ChangeOrderStatus('shipped'), 7);
        $returns = $this->shop->returns->forReceipt($this->receipt);
        self::assertSame([true, '2026-11-16'], [$returns->accepting, $returns->until]);
        self::assertSame([['Lamp', 2]], array_map(static fn(ReturnedItemView $item): array => [$item->name, $item->quantity], $returns->returnable));

        $this->shop->clock->modify('+31 days');
        self::assertFalse($this->shop->returns->forReceipt($this->receipt)->accepting);
        $this->expectException(ReturnRuleViolation::class);
        $this->shop->returns->request($this->receipt, new RequestReturn([new ReturnItem(0, 1)], 'damaged'));
    }

    public function testTheShopHearsApprovesReceivesAndRefundsTheItems(): void
    {
        $this->shop->orders->changeStatus($this->order, new ChangeOrderStatus('shipped'), 7);

        $return = $this->shop->returns->request($this->receipt, new RequestReturn([new ReturnItem(0, 1)], 'damaged', 'The shade is cracked.'));
        self::assertSame(['requested', '30'], [$return->status, $return->amount]);
        $order = $this->shop->orders->order($this->order);
        self::assertSame('The customer asks to return 1 × Lamp: it arrived damaged. "The shade is cracked."', $order->attention);
        self::assertSame([['Lamp', 1]], array_map(static fn(ReturnedItemView $item): array => [$item->name, $item->quantity], $this->shop->returns->forOrder($this->order)->returnable), 'one is asked for');
        try {
            $this->shop->returns->request($this->receipt, new RequestReturn([new ReturnItem(0, 2)], 'other'));
            self::fail('only one is left');
        } catch (ReturnRuleViolation $e) {
            self::assertSame(['orillacart_invalid_return_items', '1 of "Lamp" can go back.'], [$e->errorCode, $e->getMessage()]);
        }

        $this->shop->returns->approve($return->id, new AnswerReturn('Send it to 1 Shop St, Sofia.'), 7);
        $mails = $this->shop->mail->summary();
        self::assertStringContainsString('Your return of 1 × Lamp is approved. Send it to 1 Shop St, Sofia.', $this->shop->mail->emails[\count($mails) - 1]->html, 'the customer reads it as a note');

        try {
            $this->shop->returns->refund($return->id, new RefundReturn(), 7);
            self::fail('it has not come back');
        } catch (ReturnRuleViolation $e) {
            self::assertSame('orillacart_return_not_refundable', $e->errorCode);
        }
        $this->shop->returns->receive($return->id, 7);
        $refunded = $this->shop->returns->refund($return->id, new RefundReturn(outside: true), 7);

        self::assertSame('refunded', $refunded->status);
        $order = $this->shop->orders->order($this->order);
        self::assertSame(['30', 1, 'shipped'], [$order->refundedTotal, $order->lines[0]->refundedQuantity, $order->status]);
        self::assertSame([['Lamp', 1]], array_map(static fn(ReturnedItemView $item): array => [$item->name, $item->quantity], $this->shop->returns->forOrder($this->order)->returnable), 'the other may still go back');
    }

    public function testARefusalSaysWhyAndLeavesTheItemsReturnable(): void
    {
        $this->shop->orders->changeStatus($this->order, new ChangeOrderStatus('shipped'), 7);
        $return = $this->shop->returns->request($this->receipt, new RequestReturn([new ReturnItem(0, 2)], 'no_longer_needed'));

        try {
            $this->shop->returns->refuse($return->id, new AnswerReturn(''), 7);
            self::fail('no reason');
        } catch (ReturnRuleViolation $e) {
            self::assertSame('orillacart_invalid_return_answer', $e->errorCode);
        }
        self::assertSame('refused', $this->shop->returns->refuse($return->id, new AnswerReturn('Opened items do not go back.'), 7)->status);
        self::assertSame(2, $this->shop->returns->forOrder($this->order)->returnable[0]->quantity);
    }

    public function testAShopThatTakesNoReturnsOffersNone(): void
    {
        $this->shop->settings->save(new StoreSettings(new Currency('EUR'), 2, WeightUnit::Kilogram, DimensionUnit::Centimetre, 'BG'));
        $this->shop->orders->changeStatus($this->order, new ChangeOrderStatus('shipped'), 7);

        self::assertSame([false, null], [$this->shop->returns->forReceipt($this->receipt)->accepting, $this->shop->returns->forReceipt($this->receipt)->until]);
    }
}
