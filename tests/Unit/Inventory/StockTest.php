<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Inventory;

use OrillaCart\Inventory\Domain\InsufficientStock;
use OrillaCart\Inventory\Domain\InventoryRuleViolation;
use OrillaCart\Inventory\Domain\Stock;
use OrillaCart\Inventory\Domain\StockItem;
use OrillaCart\Inventory\Domain\StockItemKind;
use OrillaCart\Inventory\Domain\StockLevel;
use OrillaCart\Inventory\Domain\Stockroom;
use OrillaCart\Inventory\Domain\StockroomDetails;
use OrillaCart\Tests\Fixtures\Inventory\InMemoryStockroomRepository;
use PHPUnit\Framework\TestCase;

final class StockTest extends TestCase
{
    private StockItem $item;
    private InMemoryStockroomRepository $stockrooms;

    protected function setUp(): void
    {
        $this->item = new StockItem(StockItemKind::Product, 7);
        $this->stockrooms = new InMemoryStockroomRepository();
    }

    public function testAnItemWithoutStockLevelsIsNotLimited(): void
    {
        $stock = new Stock($this->item, []);

        self::assertFalse($stock->isLimited());
        self::assertNull($stock->available());
        self::assertSame([], $stock->take(1000));
    }

    public function testOnlyActiveStockroomsCount(): void
    {
        $stock = new Stock($this->item, [
            $this->level('Sofia', 2, 5),
            $this->level('Archive', 1, 10, active: false),
        ]);

        self::assertSame(5, $stock->available());
        self::assertSame(0, new Stock($this->item, [$this->level('Archive', 1, 10, active: false)])->available(), 'still limited, with nothing to sell');
    }

    public function testStockIsTakenFromTheFastestStockroomAndTheSmallestPileFirst(): void
    {
        $sofia = $this->level('Sofia', 2, 5);
        $plovdiv = $this->level('Plovdiv', 2, 1);
        $berlin = $this->level('Berlin', 7, 3);
        $empty = $this->level('Varna', 1, 0);
        $stock = new Stock($this->item, [$berlin, $sofia, $plovdiv, $empty]);

        $taken = $stock->take(8);

        self::assertSame([$plovdiv->stockroom()->id() => 1, $sofia->stockroom()->id() => 5, $berlin->stockroom()->id() => 2], $taken);
        self::assertSame([0, 0, 1, 0], [$sofia->quantity(), $plovdiv->quantity(), $berlin->quantity(), $empty->quantity()]);
    }

    public function testAShortfallTakesNothing(): void
    {
        $sofia = $this->level('Sofia', 2, 5);
        $stock = new Stock($this->item, [$sofia, $this->level('Archive', 1, 10, active: false)]);

        try {
            $stock->take(6);
            self::fail('Six were taken from five.');
        } catch (InsufficientStock $e) {
            self::assertSame([6, 5], [$e->requested, $e->available]);
        }
        self::assertSame(5, $sofia->quantity());
    }

    public function testLevelsAreNeverBelowZero(): void
    {
        $this->expectException(InventoryRuleViolation::class);

        $this->level('Sofia', 1, -1);
    }

    private function level(string $name, int $days, int $quantity, bool $active = true): StockLevel
    {
        $stockroom = new Stockroom(new StockroomDetails($name, 0, $days, $active));
        $this->stockrooms->save($stockroom);

        return new StockLevel($stockroom, $this->item, $quantity);
    }
}
