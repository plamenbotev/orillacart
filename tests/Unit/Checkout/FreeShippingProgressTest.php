<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Checkout;

use OrillaCart\Cart\Application\Dto\AddToCart;
use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Checkout\Application\FreeShippingProgress;
use OrillaCart\Shipping\Application\Dto\SaveCarrier;
use OrillaCart\Shipping\Application\Dto\SaveRange;
use OrillaCart\Shipping\Application\Dto\SaveShippingRate;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use PHPUnit\Framework\TestCase;

/**
 * How far a cart is from free shipping, at the shop's country (Bulgaria) for a shopper without a saved address:
 * a lamp is 20, a courier to Bulgaria 5, free from 50.
 */
final class FreeShippingProgressTest extends TestCase
{
    private TestShop $shop;
    private FreeShippingProgress $progress;

    protected function setUp(): void
    {
        $this->shop = new TestShop();
        $this->shop->content->add(10, 'Lamp');
        $this->shop->products->save(10, new SaveProduct('20', weight: '1'));
        $this->shop->content->add(20, 'E-book');
        $this->shop->products->save(20, new SaveProduct('8', 'virtual'));
        $this->progress = new FreeShippingProgress($this->shop->checkout);
    }

    public function testItSaysHowMuchMoreShipsTheCartFreeAndWhenItDoes(): void
    {
        $this->shop->carriers->create(new SaveCarrier('Courier', rates: [
            new SaveShippingRate('Bulgaria', '5', regions: ['BG']),
            new SaveShippingRate('Free over 50', '0', regions: ['BG'], subtotal: new SaveRange('50')),
        ]));
        $owner = $this->cart([10, 1], [20, 1]);

        $short = $this->progress->of($owner);
        self::assertNotNull($short);
        self::assertFalse($short->reached);
        self::assertSame(['28', '50', '22', 'EUR'], [$short->spent, $short->threshold, $short->remaining, $short->currency], 'the e-book counts towards it too');

        $owner = $this->add($owner, 10, 2);
        $free = $this->progress->of($owner);
        self::assertNotNull($free);
        self::assertTrue($free->reached);
        self::assertNull($free->remaining);
    }

    public function testNothingWithoutAFreeRateForTheCartOrAnythingToShip(): void
    {
        self::assertNull($this->progress->of(new CartOwner(null)), 'an empty cart');

        $this->shop->carriers->create(new SaveCarrier('Courier', rates: [
            new SaveShippingRate('Bulgaria', '5', regions: ['BG']),
            new SaveShippingRate('Free in Greece over 50', '0', regions: ['GR'], subtotal: new SaveRange('50')),
        ]));
        self::assertNull($this->progress->of($this->cart([10, 1])), 'the free rate does not go where the cart is estimated for');
        self::assertNull($this->progress->of($this->cart([20, 1])), 'nothing to ship');
    }

    /**
     * @param array{int, int} ...$lines product and quantity
     */
    private function cart(array ...$lines): CartOwner
    {
        $owner = new CartOwner(null);
        foreach ($lines as [$product, $quantity]) {
            $owner = $this->add($owner, $product, $quantity);
        }

        return $owner;
    }

    private function add(CartOwner $owner, int $product, int $quantity): CartOwner
    {
        return new CartOwner($this->shop->carts->add($owner, new AddToCart($product, quantity: $quantity))->newToken ?? $owner->token);
    }
}
