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
use OrillaCart\Returns\Application\Dto\AskToWithdraw;
use OrillaCart\Returns\Application\Dto\ConfirmWithdrawal;
use OrillaCart\Returns\Application\Dto\OpenWithdrawal;
use OrillaCart\Returns\Application\Dto\ReturnItem;
use OrillaCart\Settings\Domain\DimensionUnit;
use OrillaCart\Settings\Domain\StoreSettings;
use OrillaCart\Settings\Domain\WeightUnit;
use OrillaCart\Shared\Domain\Currency;
use OrillaCart\Shared\Domain\NotFound;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use PHPUnit\Framework\TestCase;

/**
 * The withdrawal function as anyone reaches it: the order's number and email get a link mailed to the order's own
 * address, and the answer is the same whatever they are; only the link shows the items and files the withdrawal.
 */
final class WithdrawalLinksTest extends TestCase
{
    private TestShop $shop;
    private int $order;

    protected function setUp(): void
    {
        $this->shop = new TestShop();
        $this->shop->settings->save(new StoreSettings(new Currency('EUR'), 2, WeightUnit::Kilogram, DimensionUnit::Centimetre, 'BG', returnDays: 30));
        $this->shop->content->add(10, 'Lamp');
        $this->shop->products->save(10, new SaveProduct('30', 'virtual', stockMode: 'always_available'));
        $this->shop->paymentMethods->update('bank_transfer', new SavePaymentMethod('Bank transfer', true));
        $owner = new CartOwner($this->shop->carts->add(new CartOwner(null), new AddToCart(10, quantity: 2))->newToken);
        $this->order = $this->shop->checkout->placeOrder($owner, new PlaceOrder('ada@example.com', new AddressData('Ada', 'Lovelace', '1 Vitosha Blvd', 'Sofia', '1000', 'BG', '+359 2 000 0000'), payment: 'bank_transfer'))->number;
        $this->shop->orders->markPaid($this->order, null);
        $this->shop->jobs->runAll();
        $this->shop->mail->emails = [];
    }

    public function testTheLinkGoesToTheOrdersOwnAddressAndAWithdrawalCountsAsDeclaredWhenAsked(): void
    {
        $asked = $this->shop->clock->now();
        $counted = [];
        $once = static function (int $order) use (&$counted): bool {
            $counted[] = $order;

            return \count($counted) <= 1;
        };
        $this->shop->withdrawals->ask(new AskToWithdraw($this->order, 'ADA@example.com'), $once);
        $this->shop->withdrawals->ask(new AskToWithdraw($this->order, 'ada@example.com'), $once);
        $this->shop->jobs->runAll();

        $sent = $this->shop->mail->ofKind('withdrawal_link');
        self::assertSame(['ada@example.com'], array_map(static fn($email): string => $email->to, $sent), 'to the order\'s address, as often as the cap lets');
        self::assertSame([$this->order, $this->order], $counted);
        self::assertSame(1, preg_match('/orillacart_withdrawal=([0-9.a-f%]+)/', $sent[0]->html ?? '', $link));
        $token = rawurldecode($link[1]);

        $this->shop->clock->modify('+2 days');
        self::assertSame([['line' => 0, 'name' => 'Lamp', 'quantity' => 2]], $this->shop->withdrawals->withdrawable(new OpenWithdrawal($token))->lines);
        $withdrawn = $this->shop->withdrawals->withdraw(new ConfirmWithdrawal($token, 'Ada Lovelace', 'ada@example.com', [new ReturnItem(0, 1)]));
        self::assertSame($asked->format(\DATE_ATOM), $withdrawn->declaredAt, 'declared when asked for, not when the link was followed');
        self::assertSame([['line' => 0, 'name' => 'Lamp', 'quantity' => 1]], $this->shop->withdrawals->withdrawable(new OpenWithdrawal($token))->lines, 'the link works while items are left');
    }

    public function testTheAnswerIsTheSameForNoOrderAnotherEmailAndAClosedOrderAndNothingIsMailed(): void
    {
        $this->shop->withdrawals->ask(new AskToWithdraw($this->order, 'eve@example.com'));
        $this->shop->withdrawals->ask(new AskToWithdraw(987654, 'ada@example.com'));
        $owner = new CartOwner($this->shop->carts->add(new CartOwner(null), new AddToCart(10))->newToken);
        $unpaid = $this->shop->checkout->placeOrder($owner, new PlaceOrder('ada@example.com', new AddressData('Ada', 'Lovelace', '1 Vitosha Blvd', 'Sofia', '1000', 'BG', '+359 2 000 0000'), payment: 'bank_transfer'))->number;
        $this->shop->orders->changeStatus($unpaid, new ChangeOrderStatus('cancelled'), 7);
        $this->shop->jobs->runAll();
        $this->shop->mail->emails = [];
        $this->shop->withdrawals->ask(new AskToWithdraw($unpaid, 'ada@example.com'));
        $this->shop->jobs->runAll();

        self::assertSame([], $this->shop->mail->ofKind('withdrawal_link'), 'ask() returns nothing either way, and only an open order\'s address hears');
    }

    public function testALinkOpensOnlyItsOwnOrderAndNotForever(): void
    {
        $token = $this->shop->withdrawals->token($this->order, 'ada@example.com', $this->shop->clock->now()->getTimestamp());
        [$number, $at, $signature] = explode('.', $token) + ['', '', ''];
        $forgeries = ["{$number}.{$at}." . str_repeat('0', 32), ((int) $number + 1) . ".{$at}.{$signature}", "{$number}." . ((int) $at - 1) . ".{$signature}", 'nonsense'];
        foreach ($forgeries as $forged) {
            try {
                $this->shop->withdrawals->withdrawable(new OpenWithdrawal($forged));
                self::fail('a forged link opened an order');
            } catch (NotFound $e) {
                self::assertSame('orillacart_withdrawal_link_invalid', $e->errorCode);
            }
        }
        $this->shop->clock->modify('+8 days');
        $this->expectException(NotFound::class);
        $this->shop->withdrawals->withdrawable(new OpenWithdrawal($token));
    }
}
