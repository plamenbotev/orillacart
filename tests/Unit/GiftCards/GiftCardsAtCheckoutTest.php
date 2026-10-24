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
use OrillaCart\GiftCards\Application\Dto\GiftCardSettingsView;
use OrillaCart\GiftCards\Application\Dto\IssueGiftCard;
use OrillaCart\GiftCards\Domain\GiftCardRuleViolation;
use OrillaCart\Ordering\Application\Dto\AddressData;
use OrillaCart\Ordering\Application\Dto\RefundLine;
use OrillaCart\Payment\Application\Dto\MakeRefund;
use OrillaCart\Payment\Application\Dto\SavePaymentMethod;
use OrillaCart\Shipping\Application\Dto\SaveCarrier;
use OrillaCart\Shipping\Application\Dto\SaveShippingRate;
use OrillaCart\Tax\Application\Dto\SaveTaxGroup;
use OrillaCart\Tax\Application\Dto\SaveTaxRate;
use OrillaCart\Tax\Domain\TaxBasis;
use OrillaCart\Tax\Domain\TaxSettings;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use PHPUnit\Framework\TestCase;

final class GiftCardsAtCheckoutTest extends TestCase
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
        $this->shop->content->add(20, 'Gift card');
        $this->shop->products->save(20, new SaveProduct('25', 'virtual', taxable: false));
        $this->rate = $this->shop->carriers->create(new SaveCarrier('Courier', rates: [new SaveShippingRate('Bulgaria', '5', regions: ['BG'])]))->rates[0]->id;
        $this->shop->paymentMethods->update('bank_transfer', new SavePaymentMethod('Bank transfer', true));
        $this->shop->paymentMethods->update('paypal', new SavePaymentMethod('PayPal', true, 3, settings: ['client_id' => 'id', 'client_secret' => 'secret', 'sandbox' => true]));
    }

    public function testAGiftCardPaysPartOfTheOrderAndTheRestIsPaidTheWayChosen(): void
    {
        $issued = $this->shop->giftCards->issue(new IssueGiftCard('30', recipientName: 'Ada', recipientEmail: 'ada@example.com', message: 'Happy birthday!'), 1);
        self::assertSame([['to' => 'ada@example.com', 'code' => $issued->code, 'amount' => '30']], $this->shop->giftCardMail->sent);
        self::assertMatchesRegularExpression('/^[A-Z2-9]{4}(-[A-Z2-9]{4}){3}$/', $issued->code);
        $owner = $this->cart([10, 1]);

        $summary = $this->shop->checkout->applyGiftCard($owner, new GiftCardCodeInput(strtolower(str_replace('-', ' ', $issued->code))));
        $summary = $this->shop->checkout->summary($owner, new CheckoutDetails('BG', shippingOption: "rate:{$this->rate}"));
        self::assertSame(['54', '30', '24', true], [$summary->total, $summary->giftCardTotal, $summary->amountDue, $summary->needsPayment]);
        self::assertSame([['Gift card ····' . substr($issued->code, -4), '30', null]], array_map(static fn($card): array => [$card->label, $card->amount, $card->refused], $summary->giftCards));

        $placed = $this->shop->checkout->placeOrder($owner, $this->order('paypal'));
        $order = $this->shop->orders->order($placed->number);
        self::assertSame(['54', '30', '24'], [$order->total, $order->giftCardTotal, $order->amountDue]);
        $request = $this->shop->payPalApi->created[0];
        self::assertSame(['24.00', [], null], [$request->amount, $request->items, $request->breakdown], 'the provider is asked for the rest only');
        self::assertSame('0', $this->shop->giftCards->card($issued->card->id)->card->balance);

        $this->shop->payPalApi->captureStatus = 'DECLINED';
        $this->shop->outcomes->returned('paypal', $this->shop->returnQuery('PAYPAL-1', 'paypal'), false);
        self::assertSame('failed', $this->shop->orders->order($placed->number)->status);
        $card = $this->shop->giftCards->card($issued->card->id);
        self::assertSame('30', $card->card->balance, 'the failed order gave it back');
        self::assertSame(['30', '-30', '30'], array_reverse(array_map(static fn($entry): string => $entry->amount, $card->entries)));
    }

    public function testAGiftCardThatPaysEverythingLeavesNothingToPay(): void
    {
        $issued = $this->shop->giftCards->issue(new IssueGiftCard('100'), 1);
        $owner = $this->cart([10, 1]);
        $this->shop->checkout->applyGiftCard($owner, new GiftCardCodeInput($issued->code));

        $summary = $this->shop->checkout->summary($owner, new CheckoutDetails('BG', shippingOption: "rate:{$this->rate}"));
        self::assertSame(['54', '0', false, []], [$summary->giftCardTotal, $summary->amountDue, $summary->needsPayment, $summary->paymentMethods]);

        $placed = $this->shop->checkout->placeOrder($owner, $this->order(null));
        $order = $this->shop->orders->order($placed->number);
        self::assertSame(['processing', '54', '0'], [$order->status, $order->refundable, $order->amountDue]);
        self::assertSame('46', $this->shop->giftCards->card($issued->card->id)->card->balance);
    }

    public function testACardThatCannotPaySaysWhy(): void
    {
        $owner = $this->cart([10, 1]);
        foreach (['NOT A CODE', 'AAAA-BBBB-CCCC-DDDD'] as $code) {
            try {
                $this->shop->checkout->applyGiftCard($owner, new GiftCardCodeInput($code));
                self::fail($code);
            } catch (CheckoutRuleViolation $e) {
                self::assertSame('This gift card is not valid.', $e->getMessage());
            }
        }

        $issued = $this->shop->giftCards->issue(new IssueGiftCard('10', expiresAt: '2026-11-01T00:00:00+00:00'), 1);
        $this->shop->checkout->applyGiftCard($owner, new GiftCardCodeInput($issued->code));
        $this->shop->clock->modify('+1 month');
        $summary = $this->shop->checkout->summary($owner, new CheckoutDetails('BG'));
        self::assertSame([[$issued->code, 'This gift card has expired.']], array_map(static fn($card): array => [$card->code, $card->refused], $summary->giftCards));

        $this->expectExceptionMessage('This gift card has expired. Remove it to order.');
        $this->shop->checkout->placeOrder($owner, $this->order('bank_transfer'));
    }

    public function testACardSpentByAnotherOrderInTheMeantimeStopsTheOrder(): void
    {
        $issued = $this->shop->giftCards->issue(new IssueGiftCard('30'), 1);
        $owner = $this->cart([10, 1]);
        $this->shop->checkout->applyGiftCard($owner, new GiftCardCodeInput($issued->code));
        $plan = $this->shop->giftCardPayments->plan([$issued->code], null, false, \OrillaCart\Shared\Domain\Money::of('54'), 'EUR');
        $this->shop->giftCards->adjust($issued->card->id, new AdjustBalance('-10', 'Spent in the shop downstairs'), 1);

        $this->expectException(GiftCardRuleViolation::class);
        $this->shop->transactions->run(fn() => $this->shop->giftCardPayments->pay(999, $plan, 'EUR'));
    }

    public function testACustomerPaysWithTheirStoreCredit(): void
    {
        $ada = $this->shop->accounts->add('ada@example.com', 'Ada');
        $credit = $this->shop->giftCards->adjustStoreCredit($ada, new AdjustBalance('20', 'Sorry for the late delivery'), 1);
        self::assertSame(['20', 'Store credit'], [$credit->card->balance, $credit->card->label]);
        $owner = new CartOwner($this->shop->carts->add(new CartOwner(null, $ada), new AddToCart(10))->newToken, $ada);

        $summary = $this->shop->checkout->summary($owner, new CheckoutDetails('BG', shippingOption: "rate:{$this->rate}"));
        self::assertSame(['20', false, '0'], [$summary->storeCredit, $summary->usingStoreCredit, $summary->giftCardTotal], 'offered, not used until asked');
        $summary = $this->shop->checkout->useStoreCredit($owner, true);
        self::assertSame([true, '20'], [$summary->usingStoreCredit, $summary->giftCardTotal]);

        $placed = $this->shop->checkout->placeOrder($owner, $this->order('bank_transfer'));
        self::assertSame([['label' => 'Store credit', 'amount' => '20']], $this->shop->orders->order($placed->number)->giftCards);
        self::assertSame('0', $this->shop->giftCards->storeCredit($ada)->card->balance);

        $this->expectExceptionMessage('Log in to use your store credit.');
        $this->shop->checkout->useStoreCredit($this->cart([10, 1]), true);
    }

    public function testTheShopTakesOffNoMoreStoreCreditThanThereIs(): void
    {
        $ada = $this->shop->accounts->add('ada@example.com', 'Ada');
        $this->shop->giftCards->adjustStoreCredit($ada, new AdjustBalance('5', 'Goodwill'), 1);

        $this->expectExceptionMessage('Only 5 EUR is left to take off.');
        $this->shop->giftCards->adjustStoreCredit($ada, new AdjustBalance('-6', 'Too much'), 1);
    }

    public function testAProductSoldAsAGiftCardMakesOneForEachPaidItem(): void
    {
        $this->shop->giftCards->saveSettings(new GiftCardSettingsView([20], 12));
        $placed = $this->shop->checkout->placeOrder($this->cart([20, 2]), $this->order('bank_transfer', null));
        self::assertSame([], $this->shop->giftCardMail->sent, 'not before it is paid');

        $this->shop->orders->markPaid($placed->number, null);
        $this->shop->orders->markPaid($placed->number, null);

        self::assertSame([['ada@example.com', '25'], ['ada@example.com', '25']], array_map(static fn(array $sent): array => [$sent['to'], $sent['amount']], $this->shop->giftCardMail->sent), 'once per order');
        $cards = $this->shop->giftCards->page(new \OrillaCart\GiftCards\Application\Dto\GiftCardQuery())->cards;
        self::assertSame([[$placed->number, '25', $this->shop->clock->now()->modify('+12 months')->format('Y-m-d')]], array_values(array_unique(array_map(static fn($card): array => [$card->orderId, $card->balance, substr($card->expiresAt ?? '', 0, 10)], $cards), \SORT_REGULAR)));
    }

    public function testARefundGoesBackAsStoreCreditOrForAGuestAsAGiftCard(): void
    {
        $ada = $this->shop->accounts->add('ada@example.com', 'Ada');
        $owner = new CartOwner($this->shop->carts->add(new CartOwner(null, $ada), new AddToCart(10))->newToken, $ada);
        $placed = $this->shop->checkout->placeOrder($owner, $this->order('bank_transfer'));
        $this->shop->orders->markPaid($placed->number, null);

        $this->shop->refunds->refund($placed->number, new MakeRefund('10', reason: 'Scratched', storeCredit: true), 1);
        self::assertSame('10', $this->shop->giftCards->storeCredit($ada)->card->balance);
        self::assertSame('store_credit', $this->shop->refunds->forOrder($placed->number)->refunds[0]->method);

        $guest = $this->shop->checkout->placeOrder($this->cart([10, 1]), $this->order('bank_transfer'));
        $this->shop->orders->markPaid($guest->number, null);
        $this->shop->refunds->refund($guest->number, new MakeRefund('5', storeCredit: true), 1);
        self::assertSame([['ada@example.com', '5']], array_map(static fn(array $sent): array => [$sent['to'], $sent['amount']], $this->shop->giftCardMail->sent));
        self::assertStringContainsString('as gift card ····', implode(' ', array_map(static fn($note): string => $note->text, $this->shop->orders->order($guest->number)->notes)));
        $this->shop->jobs->runAll();
        $told = implode(' ', array_map(static fn($email): string => $email->html, array_filter($this->shop->mail->emails, static fn($email): bool => $email->kind === 'order_refunded')));
        self::assertStringContainsString('back as store credit', $told, 'not "we refunded it to you"');
        self::assertStringContainsString('back as a gift card', $told);
    }

    public function testTheCardsOfARefundedOrderPayNoMoreAndWhatTheySpentIsSaid(): void
    {
        $this->shop->giftCards->saveSettings(new GiftCardSettingsView([20], 12));
        $placed = $this->shop->checkout->placeOrder($this->cart([20, 2]), $this->order('bank_transfer', null));
        $this->shop->orders->markPaid($placed->number, null);
        $cards = $this->cards();
        $this->shop->giftCards->adjust($cards[1]->id, new AdjustBalance('-10', 'Spent at the shop'), 1);

        $this->shop->refunds->refund($placed->number, new MakeRefund('25', [new RefundLine(0, 1)], outside: true), 1);
        self::assertSame([[false, '0'], [true, '15']], array_map(static fn($card): array => [$card->active, $card->balance], $this->cards()), 'the one with the most left goes first');

        $this->shop->refunds->refund($placed->number, new MakeRefund('25', outside: true), 1);
        self::assertSame([[false, '0'], [false, '0']], array_map(static fn($card): array => [$card->active, $card->balance], $this->cards()));
        $notes = implode(' ', array_map(static fn($note): string => $note->text, $this->shop->orders->order($placed->number)->notes));
        self::assertStringContainsString('had spent 10 of 25 EUR: settle that with the customer', $notes);
    }

    public function testACardWhoseEmailDidNotGoIsForTheShopToSendAgain(): void
    {
        $this->shop->giftCards->saveSettings(new GiftCardSettingsView([20], 12));
        $this->shop->giftCardMail->refuse = true;
        $placed = $this->shop->checkout->placeOrder($this->cart([20, 1]), $this->order('bank_transfer', null));
        $this->shop->orders->markPaid($placed->number, null);

        $order = $this->shop->orders->order($placed->number);
        self::assertNotNull($order->attention);
        self::assertStringContainsString('did not go', $order->attention);
        self::assertStringNotContainsString('made and sent', implode(' ', array_map(static fn($note): string => $note->text, $order->notes)));
    }

    public function testCardsSplitFromOneLineAreInCentsAndAddUpToWhatWasPaid(): void
    {
        $this->shop->giftCards->saveSettings(new GiftCardSettingsView([20], 12));
        $this->shop->coupons->create(new SaveCoupon('TEN', 'order_amount', '10'));
        $owner = $this->cart([20, 3]);
        $this->shop->checkout->applyCoupon($owner, new CouponCode('TEN'));
        $placed = $this->shop->checkout->placeOrder($owner, $this->order('bank_transfer', null));
        $this->shop->orders->markPaid($placed->number, null);

        self::assertSame(['21.67', '21.67', '21.66'], array_map(static fn(array $sent): string => $sent['amount'], $this->shop->giftCardMail->sent), '65 in three');
    }

    public function testAGuestsRefundAsAGiftCardDoesNotExpire(): void
    {
        $this->shop->giftCards->saveSettings(new GiftCardSettingsView([], 12));
        $guest = $this->shop->checkout->placeOrder($this->cart([10, 1]), $this->order('bank_transfer'));
        $this->shop->orders->markPaid($guest->number, null);

        $this->shop->refunds->refund($guest->number, new MakeRefund('5', storeCredit: true), 1);

        self::assertSame([null], $this->shop->giftCardMail->expiries, 'the customer\'s money, not a gift');
    }

    public function testAnOrderTakesUpToFiveGiftCards(): void
    {
        $owner = $this->cart([10, 1]);
        for ($i = 0; $i < 5; ++$i) {
            $this->shop->checkout->applyGiftCard($owner, new GiftCardCodeInput($this->shop->giftCards->issue(new IssueGiftCard('1'), 1)->code));
        }

        $this->expectExceptionMessage('An order takes up to 5 gift cards.');
        $this->shop->checkout->applyGiftCard($owner, new GiftCardCodeInput($this->shop->giftCards->issue(new IssueGiftCard('1'), 1)->code));
    }

    /** @return list<\OrillaCart\GiftCards\Application\Dto\GiftCardView> by id */
    private function cards(): array
    {
        $cards = $this->shop->giftCards->page(new \OrillaCart\GiftCards\Application\Dto\GiftCardQuery())->cards;
        usort($cards, static fn($a, $b): int => $a->id <=> $b->id);

        return $cards;
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

    private function order(?string $payment, ?int $rate = -1): PlaceOrder
    {
        return new PlaceOrder('ada@example.com', new AddressData('Ada', 'Lovelace', '1 Vitosha Blvd', 'Sofia', '1000', 'BG', '+359 2 000 0000'), shippingOption: $rate === -1 ? "rate:{$this->rate}" : null, payment: $payment);
    }
}
