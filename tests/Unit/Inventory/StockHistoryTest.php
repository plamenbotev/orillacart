<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Inventory;

use OrillaCart\Cart\Application\Dto\AddToCart;
use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Catalog\Application\Dto\StockHistoryQuery;
use OrillaCart\Catalog\Application\StockHistory;
use OrillaCart\Catalog\Application\StockItemNames;
use OrillaCart\Checkout\Application\Dto\PlaceOrder;
use OrillaCart\Inventory\Application\Dto\ChangeStockLevel;
use OrillaCart\Inventory\Application\Dto\SaveStockroom;
use OrillaCart\Inventory\Application\Dto\SetStockLevel;
use OrillaCart\Inventory\Application\Dto\SetStockLevels;
use OrillaCart\Inventory\Application\Dto\StockItemRef;
use OrillaCart\Inventory\Application\Dto\StockNote;
use OrillaCart\Ordering\Application\Dto\AddressData;
use OrillaCart\Ordering\Application\Dto\ChangeOrderStatus;
use OrillaCart\Payment\Application\Dto\SavePaymentMethod;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use OrillaCart\Tests\Fixtures\Shared\FixedUserNames;
use PHPUnit\Framework\TestCase;

final class StockHistoryTest extends TestCase
{
    public function testEveryMoveIsKeptWithWhyAndForWhichOrderOrByWhom(): void
    {
        $shop = new TestShop();
        $room = $shop->stockrooms->create(new SaveStockroom('Sofia'))->id;
        $shop->content->add(20, 'E-book');
        $shop->products->save(20, new SaveProduct('8', 'virtual', sku: 'BOOK'));
        $shop->paymentMethods->update('bank_transfer', new SavePaymentMethod('Bank transfer', true));
        $shop->stock->setLevels(StockItemRef::product(20), new SetStockLevels([new SetStockLevel($room, 5)]), new StockNote(userId: 7));

        $owner = new CartOwner($shop->carts->add(new CartOwner(null), new AddToCart(20, quantity: 2))->newToken);
        $order = $shop->checkout->placeOrder($owner, new PlaceOrder('ada@example.com', new AddressData('Ada', 'Lovelace', '1 Vitosha Blvd', 'Sofia', '1000', 'BG', '+359 2 000 0000'), payment: 'bank_transfer'));
        $shop->orders->changeStatus($order->number, new ChangeOrderStatus('cancelled'), 7);

        $history = new StockHistory($shop->stock, new StockItemNames($shop->productRecords, $shop->content), $shop->productRecords, new FixedUserNames())->history(new StockHistoryQuery(product: 20));

        self::assertSame([
            ['release', 2, 5, $order->number, 'Mara'],
            ['sale', -2, 3, $order->number, null],
            ['count', 5, 5, null, 'Mara'],
        ], array_map(static fn(array $movement): array => [$movement['reason'], $movement['delta'], $movement['after'], $movement['order_id'], $movement['user']], $history->movements), 'newest first');
        self::assertSame(['E-book', 'BOOK', 'Sofia'], [$history->movements[0]['name'], $history->movements[0]['sku'], $history->movements[0]['stockroom']]);
        self::assertSame([
            ['count', 'Sofia', 5, 0],
            ['release', 'Sofia', 2, 0],
            ['sale', 'Sofia', 0, 2],
        ], array_map(static fn(array $total): array => [$total['reason'], $total['stockroom'], $total['in'], $total['out']], $history->totals));
        self::assertCount(1, new StockHistory($shop->stock, new StockItemNames($shop->productRecords, $shop->content), $shop->productRecords, new FixedUserNames())->history(new StockHistoryQuery(reason: 'sale'))->movements);
    }

    public function testTwoPartsOnOneLevelEachLogTheLevelAfterThem(): void
    {
        $shop = new TestShop();
        $room = $shop->stockrooms->create(new SaveStockroom('Sofia'))->id;
        $shop->content->add(20, 'E-book');
        $shop->products->save(20, new SaveProduct('8', 'virtual'));
        $shop->stock->setLevels(StockItemRef::product(20), new SetStockLevels([new SetStockLevel($room, 5)]));
        // A bundle holding the item twice takes it in two parts.
        $taken = [...$shop->stock->take([StockItemRef::product(20)], 2), ...$shop->stock->take([StockItemRef::product(20)], 1)];

        $shop->stock->record($taken, new StockNote(orderId: 42));

        $sales = array_values(array_filter($shop->stockMovements->movements, static fn($movement): bool => $movement->orderId() === 42));
        self::assertSame([[-2, 3], [-1, 2]], array_map(static fn($movement): array => [$movement->delta(), $movement->after()], $sales));
    }

    public function testAChangeIsLoggedWithTheDeltaItMadeNotTheOneLoaded(): void
    {
        $shop = new TestShop();
        $room = $shop->stockrooms->create(new SaveStockroom('Sofia'))->id;
        $shop->content->add(20, 'E-book');
        $shop->products->save(20, new SaveProduct('8', 'virtual'));
        $shop->stock->setLevels(StockItemRef::product(20), new SetStockLevels([new SetStockLevel($room, 5)]));
        $shop->stock->take([StockItemRef::product(20)], 2);

        $shop->stock->changeLevels([new ChangeStockLevel('product', 20, $room, change: 4)], new StockNote(userId: 7));

        $last = array_last($shop->stockMovements->movements);
        self::assertNotNull($last);
        self::assertSame([4, 7, 7], [$last->delta(), $last->after(), $last->userId()], 'four in, from the three a sale left');
    }
}
