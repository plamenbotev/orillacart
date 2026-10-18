<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Cart;

use OrillaCart\Cart\Application\Dto\AddToCart;
use OrillaCart\Cart\Application\Dto\CartLineView;
use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Cart\Application\Dto\ChangeQuantity;
use OrillaCart\Cart\Domain\CartRuleViolation;
use OrillaCart\Cart\Domain\Event\CartItemAdded;
use OrillaCart\Cart\Domain\Event\CartItemChanged;
use OrillaCart\Cart\Domain\Event\CartItemRemoved;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Checkout\Application\Dto\PlaceOrder;
use OrillaCart\Checkout\Domain\CheckoutRuleViolation;
use OrillaCart\Extension\Cart\CartRefusal;
use OrillaCart\Extension\Cart\CartRule;
use OrillaCart\Extension\Cart\CheckedCart;
use OrillaCart\Extension\Cart\CheckedLine;
use OrillaCart\Ordering\Application\Dto\AddressData;
use OrillaCart\Payment\Application\Dto\SavePaymentMethod;
use OrillaCart\Shared\Domain\Event\PublicEvent;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use PHPUnit\Framework\TestCase;

final class CartRulesTest extends TestCase
{
    private TestShop $shop;

    protected function setUp(): void
    {
        $this->shop = new TestShop();
        $this->shop->content->add(10, 'Mug');
        $this->shop->products->save(10, new SaveProduct('10', 'virtual'));
        $this->shop->content->add(20, 'Knife');
        $this->shop->products->save(20, new SaveProduct('5', 'virtual'));
        // At most three mugs to a cart; knives only for customers who logged in.
        $this->shop->extensions->cartRules[] = new readonly class implements CartRule {
            public function id(): string
            {
                return 'limits';
            }

            public function check(CheckedCart $cart): array
            {
                $refusals = [];
                foreach ($cart->lines as $line) {
                    if ($line->productId === 10 && $line->quantity > 3) {
                        $refusals[] = CartRefusal::line($line->id, 'At most 3 mugs to an order.');
                    }
                    if ($line->productId === 20 && $cart->customerId === null) {
                        $refusals[] = CartRefusal::line($line->id, 'Log in to buy knives.');
                    }
                }
                if (array_sum(array_map(static fn(CheckedLine $line): int => $line->quantity, $cart->lines)) > 5) {
                    $refusals[] = CartRefusal::cart('At most 5 items to an order.');
                }

                return $refusals;
            }
        };
    }

    public function testARefusedAdditionOrRaiseIsNotMade(): void
    {
        $owner = $this->owner(new AddToCart(10, quantity: 3));
        try {
            $this->shop->carts->add($owner, new AddToCart(10));
            self::fail('a fourth mug');
        } catch (CartRuleViolation $e) {
            self::assertSame(['At most 3 mugs to an order.', 'orillacart_cart_refused'], [$e->getMessage(), $e->errorCode]);
        }
        $line = $this->shop->carts->cart($owner)->cart->lines[0];
        self::assertSame(3, $line->quantity, 'nothing changed');

        $this->expectExceptionMessage('At most 3 mugs to an order.');
        $this->shop->carts->changeQuantity($owner, $line->id, new ChangeQuantity(4));
    }

    public function testTheCartShowsWhatIsRefusedAndCannotBeOrdered(): void
    {
        $rules = $this->shop->extensions->cartRules;
        $this->shop->extensions->cartRules = [];
        $owner = $this->owner(new AddToCart(10, quantity: 4));
        $this->shop->carts->add($owner, new AddToCart(20, quantity: 2));
        $this->shop->extensions->cartRules = $rules;

        $cart = $this->shop->carts->cart($owner)->cart;
        self::assertSame([[CartLineView::REFUSED, 'At most 3 mugs to an order.'], [CartLineView::REFUSED, 'Log in to buy knives.']], array_map(static fn(CartLineView $line): array => [$line->problem, $line->message], $cart->lines));
        self::assertSame(['At most 5 items to an order.'], $cart->refusals);
        self::assertFalse($cart->orderable);

        $this->shop->paymentMethods->update('bank_transfer', new SavePaymentMethod('Bank transfer', true));
        $this->expectException(CheckoutRuleViolation::class);
        $this->expectExceptionMessage('At most 3 mugs to an order. Log in to buy knives. At most 5 items to an order.');
        $this->shop->checkout->placeOrder($owner, new PlaceOrder('bob@example.com', new AddressData('Bob', 'Smith', '1 Main St', 'Sofia', '1000', 'BG', '+359 2 000 0000'), payment: 'bank_transfer'));
    }

    public function testACustomerMayBuyWhatAGuestMayNot(): void
    {
        $this->expectExceptionMessage('Log in to buy knives.');
        try {
            $this->owner(new AddToCart(20), $this->shop->accounts->add('ada@example.com'));
        } catch (CartRuleViolation) {
            self::fail('refused for a customer');
        }
        $this->owner(new AddToCart(20));
    }

    public function testAddingChangingAndRemovingAreAnnounced(): void
    {
        $seen = [];
        foreach ([CartItemAdded::class, CartItemChanged::class, CartItemRemoved::class] as $event) {
            $this->shop->events->addListener($event, static function (PublicEvent $event) use (&$seen): void {
                $seen[] = [$event::class, $event->hookArguments()];
            });
        }
        $owner = $this->owner(new AddToCart(10, quantity: 2));
        $line = $this->shop->carts->cart($owner)->cart->lines[0]->id;
        $this->shop->carts->changeQuantity($owner, $line, new ChangeQuantity(3));
        $this->shop->carts->changeQuantity($owner, $line, new ChangeQuantity(3));
        $this->shop->carts->remove($owner, $line);

        self::assertSame([
            [CartItemAdded::class, [10, null, 2, null]],
            [CartItemChanged::class, [10, null, 3, 2, null]],
            [CartItemRemoved::class, [10, null, 3, null]],
        ], $seen, 'a change to the same quantity is not one');
    }

    private function owner(AddToCart $command, ?int $customerId = null): CartOwner
    {
        return new CartOwner($this->shop->carts->add(new CartOwner(null, $customerId), $command)->newToken, $customerId);
    }
}
