<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Cart;

use OrillaCart\Cart\Application\Dto\AddToCart;
use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Cart\Application\Dto\ChangeQuantity;
use OrillaCart\Cart\Domain\CartRuleViolation;
use OrillaCart\Catalog\Application\Dto\SaveAttribute;
use OrillaCart\Catalog\Application\Dto\SaveOption;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Catalog\Domain\InvalidSelection;
use OrillaCart\Inventory\Application\Dto\SaveStockroom;
use OrillaCart\Inventory\Application\Dto\SetStockLevel;
use OrillaCart\Inventory\Application\Dto\SetStockLevels;
use OrillaCart\Inventory\Application\Dto\StockItemRef;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use PHPUnit\Framework\TestCase;

final class CartServiceTest extends TestCase
{
    private TestShop $shop;
    private int $gold;

    protected function setUp(): void
    {
        $this->shop = new TestShop();
        $this->shop->content->add(10, 'Mug');
        $mug = $this->shop->products->save(10, new SaveProduct('10', attributes: [new SaveAttribute('Finish', [new SaveOption('Plain'), new SaveOption('Gold', '2.5')])]));
        $this->gold = $mug->attributes[0]->options[1]->id;
        $room = $this->shop->stockrooms->create(new SaveStockroom('Sofia'))->id;
        $this->shop->stock->setLevels(StockItemRef::product(10), new SetStockLevels([new SetStockLevel($room, 5)]));
        $this->shop->content->add(20, 'Draft', false);
        $this->shop->products->save(20, new SaveProduct('3'));
    }

    public function testAVisitorGetsATokenAndTheSameItemsAddUp(): void
    {
        $first = $this->shop->carts->add(new CartOwner(null), new AddToCart(10, [$this->gold], 2));
        self::assertNotNull($first->newToken);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $first->newToken);

        $owner = new CartOwner($first->newToken);
        $second = $this->shop->carts->add($owner, new AddToCart(10, [$this->gold], 1));
        self::assertNull($second->newToken, 'the browser already has it');
        $this->shop->carts->add($owner, new AddToCart(10));

        $cart = $this->shop->carts->cart($owner)->cart;
        self::assertSame([['Mug', 3, '12.5', '37.5'], ['Mug', 1, '10', '10']], array_map(static fn($line): array => [$line->name, $line->quantity, $line->unitPrice, $line->total], $cart->lines));
        self::assertSame([4, '47.5', true], [$cart->items, $cart->subtotal, $cart->orderable]);
        self::assertSame([['attribute' => 'Finish', 'option' => 'Gold']], $cart->lines[0]->options);
    }

    public function testTheCartHoldsNoStockButNeverTakesMoreThanThereIs(): void
    {
        $owner = new CartOwner($this->shop->carts->add(new CartOwner(null), new AddToCart(10, quantity: 5))->newToken);

        // Another shopper can still put all five in their cart: carts do not hold stock.
        $this->shop->carts->add(new CartOwner(null), new AddToCart(10, quantity: 5));

        $this->expectException(CartRuleViolation::class);
        $this->expectExceptionMessage('Only 5 of Mug are in stock.');
        $this->shop->carts->add($owner, new AddToCart(10));
    }

    public function testQuantitiesChangeAndZeroRemovesTheLine(): void
    {
        $owner = new CartOwner($this->shop->carts->add(new CartOwner(null), new AddToCart(10))->newToken);
        $line = $this->shop->carts->cart($owner)->cart->lines[0]->id;

        self::assertSame(4, $this->shop->carts->changeQuantity($owner, $line, new ChangeQuantity(4))->cart->items);
        self::assertSame([], $this->shop->carts->changeQuantity($owner, $line, new ChangeQuantity(0))->cart->lines);
    }

    public function testOnlyProductsForSaleWithValidOptionsGoIn(): void
    {
        try {
            $this->shop->carts->add(new CartOwner(null), new AddToCart(20));
            self::fail('a draft product is not for sale');
        } catch (CartRuleViolation $e) {
            self::assertSame('orillacart_not_for_sale', $e->errorCode);
        }

        $this->expectException(InvalidSelection::class);
        $this->shop->carts->add(new CartOwner(null), new AddToCart(10, [99999]));
    }

    public function testALineThatCanNoLongerBeOrderedIsShownWithItsProblem(): void
    {
        $owner = new CartOwner($this->shop->carts->add(new CartOwner(null), new AddToCart(10, quantity: 4))->newToken);
        $room = $this->shop->stock->stock(StockItemRef::product(10))->levels[0]->stockroomId;
        $this->shop->stock->setLevels(StockItemRef::product(10), new SetStockLevels([new SetStockLevel($room, 2)]));

        $cart = $this->shop->carts->cart($owner)->cart;
        self::assertSame(['insufficient_stock', 2, false], [$cart->lines[0]->problem, $cart->lines[0]->available, $cart->orderable]);
    }

    public function testACustomerFindsTheirCartOnAnotherDevice(): void
    {
        $phone = $this->shop->carts->add(new CartOwner(null, 7), new AddToCart(10));

        $laptop = $this->shop->carts->cart(new CartOwner(null, 7));
        self::assertNotNull($laptop->newToken);
        self::assertNotSame($phone->newToken, $laptop->newToken);
        self::assertSame(1, $laptop->cart->items);
        self::assertSame(0, $this->shop->carts->cart(new CartOwner($phone->newToken))->cart->items, 'the old cookie no longer opens it');

        // A visitor who logs in keeps the cart they filled.
        $visitor = $this->shop->carts->add(new CartOwner(null), new AddToCart(10, quantity: 2));
        $this->shop->carts->cart(new CartOwner($visitor->newToken, 8));
        self::assertSame(2, $this->shop->carts->cart(new CartOwner(null, 8))->cart->items);
    }

    public function testAnotherCustomersCartIsNotServedByItsCookie(): void
    {
        // Ada fills a cart, logs out; the cookie stays in the shared browser.
        $ada = $this->shop->carts->add(new CartOwner(null, 7), new AddToCart(10, quantity: 2));
        self::assertNotNull($ada->newToken);

        self::assertSame(0, $this->shop->carts->cart(new CartOwner($ada->newToken))->cart->items, 'a visitor does not see it');
        self::assertSame(0, $this->shop->carts->cart(new CartOwner($ada->newToken, 8))->cart->items, 'nor does Ben once he logs in');
        self::assertNull($this->shop->carts->contents(new CartOwner($ada->newToken, 8)), 'and he cannot order from it');
        self::assertSame(2, $this->shop->carts->cart(new CartOwner(null, 7))->cart->items, 'Ada still has hers');
    }

    public function testLoggingInJoinsTheVisitorsCartToTheAccountsUnderANewToken(): void
    {
        $this->shop->carts->add(new CartOwner(null, 7), new AddToCart(10, [$this->gold], 1));
        $visitor = $this->shop->carts->add(new CartOwner(null), new AddToCart(10, [$this->gold], 2));
        self::assertNotNull($visitor->newToken);
        $this->shop->carts->add(new CartOwner($visitor->newToken), new AddToCart(10));

        $joined = $this->shop->carts->cart(new CartOwner($visitor->newToken, 7));

        self::assertNotNull($joined->newToken, 'the browser gets a new token');
        self::assertNotSame($visitor->newToken, $joined->newToken);
        self::assertSame([3, 1], array_map(static fn($line): int => $line->quantity, $joined->cart->lines), 'the same item adds up');
        self::assertCount(1, $this->shop->cartRecords->carts, 'the visitor\'s cart is gone');
        self::assertSame(0, $this->shop->carts->cart(new CartOwner($visitor->newToken))->cart->items, 'the visitor\'s token opens nothing');
    }

    public function testCartsNobodyTouchedForAMonthAreForgotten(): void
    {
        $this->shop->carts->add(new CartOwner(null), new AddToCart(10));
        $this->shop->clock->modify('+29 days');
        $kept = $this->shop->carts->add(new CartOwner(null), new AddToCart(10));
        $this->shop->clock->modify('+2 days');

        self::assertSame(1, $this->shop->carts->forgetAbandoned());
        self::assertSame(1, $this->shop->carts->cart(new CartOwner($kept->newToken))->cart->items);
    }
}
