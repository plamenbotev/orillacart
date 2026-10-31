<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Cart;

use OrillaCart\Cart\Application\Dto\AddToCart;
use OrillaCart\Cart\Application\Dto\CartLineView;
use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Cart\Application\Dto\ChangeQuantity;
use OrillaCart\Cart\Domain\CartRuleViolation;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Checkout\Application\Dto\PlaceOrder;
use OrillaCart\Extension\Cart\CartLineType;
use OrillaCart\Extension\Cart\CheckedCart;
use OrillaCart\Extension\Cart\CheckedLine;
use OrillaCart\Extension\Cart\KeptItem;
use OrillaCart\Ordering\Application\Dto\AddressData;
use OrillaCart\Payment\Application\Dto\SavePaymentMethod;
use OrillaCart\Shipping\Application\Dto\SaveCarrier;
use OrillaCart\Shipping\Application\Dto\SaveShippingRate;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use PHPUnit\Framework\TestCase;

/**
 * An add-on's cart line type keeps catalog products in the cart for what it holds, here a deposit of 0.25 for
 * each bottle: kept in step as the cart changes, priced by the catalog, and sold as order lines of the type.
 */
final class AddOnLinesTest extends TestCase
{
    private TestShop $shop;
    private int $rate;

    protected function setUp(): void
    {
        $this->shop = new TestShop();
        $this->shop->content->add(10, 'Bottle');
        $this->shop->products->save(10, new SaveProduct('3', weight: '1', stockMode: 'always_available'));
        $this->shop->content->add(30, 'Deposit');
        $this->shop->products->save(30, new SaveProduct('0.25', 'virtual', stockMode: 'always_available'));
        $this->shop->paymentMethods->update('bank_transfer', new SavePaymentMethod('Bank transfer', true));
        $this->rate = $this->shop->carriers->create(new SaveCarrier('Post', rates: [new SaveShippingRate('Anywhere', '5')]))->rates[0]->id;
    }

    public function testTheTypeKeepsItsLinesInStepWithTheCart(): void
    {
        $this->deposit(removable: false);
        $owner = $this->cart(4);

        self::assertSame([['product', 10, 4], ['add_on', 30, 4]], array_map(static fn(CartLineView $line): array => [$line->type, $line->productId, $line->quantity], $this->lines($owner)));
        self::assertSame(['Deposit', false, false, '1'], [$this->lines($owner)[1]->givenLabel, $this->lines($owner)[1]->removable, $this->lines($owner)[1]->quantityChangeable, $this->lines($owner)[1]->total]);

        $this->shop->carts->changeQuantity($owner, $this->lines($owner)[0]->id, new ChangeQuantity(6));
        self::assertSame(6, $this->lines($owner)[1]->quantity);
        $this->shop->carts->remove($owner, $this->lines($owner)[0]->id);
        self::assertSame([], $this->lines($owner), 'nothing to keep for');
    }

    public function testALineTheTypeDoesNotLetGoStays(): void
    {
        $this->deposit(removable: false);
        $owner = $this->cart(2);

        $this->expectException(CartRuleViolation::class);
        $this->shop->carts->remove($owner, $this->lines($owner)[1]->id);
    }

    public function testAShopperRemovesWhatTheTypeLetsThemAndHasItBack(): void
    {
        $this->deposit(removable: true);
        $owner = $this->cart(2);

        $this->shop->carts->remove($owner, $this->lines($owner)[1]->id);
        $this->shop->carts->changeQuantity($owner, $this->lines($owner)[0]->id, new ChangeQuantity(3));
        self::assertCount(1, $this->lines($owner), 'not kept again');

        $this->shop->carts->restoreGiven($owner, 'add_on:bottle_deposit');
        self::assertSame([3, 3], array_map(static fn(CartLineView $line): int => $line->quantity, $this->lines($owner)));
    }

    public function testItIsSoldAsALineOfTheType(): void
    {
        $this->deposit(removable: false);
        $number = $this->shop->checkout->placeOrder($this->cart(2), new PlaceOrder('ada@example.com', new AddressData('Ada', 'Lovelace', '1 Vitosha Blvd', 'Sofia', '1000', 'BG', '+359 2 000 0000'), shippingOption: "rate:{$this->rate}", payment: 'bank_transfer'))->number;

        $order = $this->shop->orders->order($number);
        self::assertSame([['item', null], ['add_on', ['type' => 'bottle_deposit', 'label' => 'Deposit']]], array_map(static fn($line): array => [$line->type, $line->addOn], $order->lines));
        self::assertSame('0.5', $order->lines[1]->total);
    }

    public function testATypeThatFailsKeepsItsLinesAsTheyAre(): void
    {
        $this->shop->extensions->cartLineTypes = [new class implements CartLineType {
            public function id(): string
            {
                return 'broken';
            }

            public function label(): string
            {
                return 'Broken';
            }

            public function removable(): bool
            {
                return true;
            }

            public function lines(CheckedCart $cart): array
            {
                throw new \RuntimeException('down');
            }
        }];

        self::assertCount(1, $this->lines($this->cart(1)), 'the shopper\'s items still go in');
    }

    private function deposit(bool $removable): void
    {
        $this->shop->extensions->cartLineTypes = [new readonly class ($removable) implements CartLineType {
            public function __construct(private bool $removable) {}

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
                return $this->removable;
            }

            public function lines(CheckedCart $cart): array
            {
                $bottles = array_sum(array_map(static fn(CheckedLine $line): int => $line->productId === 10 ? $line->quantity : 0, $cart->lines));

                return $bottles === 0 ? [] : [new KeptItem(30, $bottles)];
            }
        }];
    }

    private function cart(int $bottles): CartOwner
    {
        return new CartOwner($this->shop->carts->add(new CartOwner(null), new AddToCart(10, quantity: $bottles))->newToken);
    }

    /** @return list<CartLineView> */
    private function lines(CartOwner $owner): array
    {
        return $this->shop->carts->cart($owner)->cart->lines;
    }
}
