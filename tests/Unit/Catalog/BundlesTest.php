<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Catalog;

use OrillaCart\Cart\Application\Dto\AddToCart;
use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Catalog\Application\Dto\QuoteRequest;
use OrillaCart\Catalog\Application\Dto\SaveAttribute;
use OrillaCart\Catalog\Application\Dto\SaveBundle;
use OrillaCart\Catalog\Application\Dto\SaveBundleItem;
use OrillaCart\Catalog\Application\Dto\SaveOption;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Catalog\Domain\CatalogRuleViolation;
use OrillaCart\Checkout\Application\Dto\PlaceOrder;
use OrillaCart\Inventory\Application\Dto\SaveStockroom;
use OrillaCart\Inventory\Application\Dto\SetStockLevel;
use OrillaCart\Inventory\Application\Dto\SetStockLevels;
use OrillaCart\Inventory\Application\Dto\StockItemRef;
use OrillaCart\Ordering\Application\Dto\AddressData;
use OrillaCart\Ordering\Application\Dto\ChangeOrderStatus;
use OrillaCart\Payment\Application\Dto\SavePaymentMethod;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use PHPUnit\Framework\TestCase;

final class BundlesTest extends TestCase
{
    private TestShop $shop;

    protected function setUp(): void
    {
        $this->shop = new TestShop();
        $room = $this->shop->stockrooms->create(new SaveStockroom('Sofia'))->id;
        foreach ([10 => ['Camera', '300', 5], 20 => ['Battery', '20', 7]] as $id => [$name, $price, $stock]) {
            $this->shop->content->add($id, $name);
            $this->shop->products->save($id, new SaveProduct($price, 'virtual'));
            $this->shop->stock->setLevels(StockItemRef::product($id), new SetStockLevels([new SetStockLevel($room, $stock)]));
        }
        $this->shop->content->add(30, 'Camera kit');
        $this->shop->paymentMethods->update('bank_transfer', new SavePaymentMethod('Bank transfer', true));
    }

    public function testABundleIsSoldWhileEachItemIsAndTakesEachItemsStock(): void
    {
        $this->shop->products->save(30, new SaveProduct('330', 'virtual', bundle: new SaveBundle([new SaveBundleItem(10, 1), new SaveBundleItem(20, 2)])));

        $quote = $this->shop->quotes->quote(new QuoteRequest(30));
        self::assertSame(['330', 3, true], [$quote->unitPrice, $quote->available, $quote->forSale], 'seven batteries make three kits');
        self::assertSame([['Camera', 1], ['Battery', 2]], array_map(static fn($item): array => [$item->name, $item->quantity], $quote->contents));
        self::assertSame([['name' => 'Camera', 'quantity' => 1], ['name' => 'Battery', 'quantity' => 2]], $this->shop->catalog->offer(30)->contents);

        $owner = new CartOwner($this->shop->carts->add(new CartOwner(null), new AddToCart(30, quantity: 2))->newToken);
        $placed = $this->shop->checkout->placeOrder($owner, new PlaceOrder('ada@example.com', new AddressData('Ada', 'Lovelace', '1 Vitosha Blvd', 'Sofia', '1000', 'BG', '+359 2 000 0000'), payment: 'bank_transfer'));

        self::assertSame([3, 3], [$this->available(10), $this->available(20)], 'two cameras and four batteries went');
        self::assertSame([['name' => 'Camera', 'quantity' => 1], ['name' => 'Battery', 'quantity' => 2]], $this->shop->orders->byKey($placed->key)->lines[0]->contents);
        $this->shop->orders->changeStatus($placed->number, new ChangeOrderStatus('cancelled'), 1);
        self::assertSame([5, 7], [$this->available(10), $this->available(20)], 'cancelling gives each item back');
    }

    public function testABundlePricedByItsItemsTakesItsDiscountOffTheirSum(): void
    {
        $this->shop->products->save(30, new SaveProduct('0', 'virtual', bundle: new SaveBundle([new SaveBundleItem(10, 1), new SaveBundleItem(20, 2)], 'sum', '10')));

        self::assertSame('306', $this->shop->quotes->quote(new QuoteRequest(30))->unitPrice, '(300 + 2 × 20) less 10 %');
    }

    public function testABundleOfAnItemThatRanOutIsNotForSale(): void
    {
        $this->shop->products->save(30, new SaveProduct('330', 'virtual', bundle: new SaveBundle([new SaveBundleItem(10, 6)])));

        self::assertFalse($this->shop->quotes->quote(new QuoteRequest(30))->forSale, 'five cameras do not make a kit of six');
    }

    public function testABundleHoldsOnlyItemsWithNothingToChoose(): void
    {
        $this->shop->content->add(40, 'Bag');
        $this->shop->products->save(40, new SaveProduct('15', 'virtual', attributes: [new SaveAttribute('Colour', [new SaveOption('Black'), new SaveOption('Brown')], required: true)]));
        $this->shop->products->save(30, new SaveProduct('330', 'virtual', bundle: new SaveBundle([new SaveBundleItem(10, 1)])));

        foreach ([[new SaveBundleItem(40, 1), 'asks the customer to choose colour'], [new SaveBundleItem(30, 1), 'is a bundle itself'], [new SaveBundleItem(99, 1), 'no commerce details']] as [$item, $reason]) {
            try {
                $this->shop->products->save(30, new SaveProduct('330', 'virtual', bundle: new SaveBundle([$item])));
                self::fail($reason);
            } catch (CatalogRuleViolation $e) {
                self::assertStringContainsString($reason, $e->getMessage());
            }
        }
    }

    private function available(int $product): ?int
    {
        return $this->shop->stock->available([StockItemRef::product($product)]);
    }
}
