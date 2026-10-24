<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Inventory;

use OrillaCart\Inventory\Application\Dto\ChangeStockLevel;
use OrillaCart\Inventory\Application\Dto\SaveStockroom;
use OrillaCart\Inventory\Application\Dto\SetStockLevel;
use OrillaCart\Inventory\Application\Dto\SetStockLevels;
use OrillaCart\Inventory\Application\Dto\StockItemRef;
use OrillaCart\Inventory\Application\Dto\TakenStock;
use OrillaCart\Inventory\Application\StockroomService;
use OrillaCart\Inventory\Application\StockService;
use OrillaCart\Inventory\Domain\Event\StockChanged;
use OrillaCart\Inventory\Domain\InsufficientStock;
use OrillaCart\Inventory\Domain\InventoryRuleViolation;
use OrillaCart\Tests\Fixtures\Inventory\InMemoryStockLevelRepository;
use OrillaCart\Tests\Fixtures\Inventory\InMemoryStockroomRepository;
use OrillaCart\Tests\Fixtures\RecordingDispatcher;
use PHPUnit\Framework\TestCase;

final class StockServiceTest extends TestCase
{
    private RecordingDispatcher $events;
    private StockService $stock;
    private StockroomService $stockrooms;
    private int $sofia;
    private int $berlin;

    protected function setUp(): void
    {
        $levels = new InMemoryStockLevelRepository();
        $stockrooms = new InMemoryStockroomRepository($levels->removeStockroom(...));
        $this->events = new RecordingDispatcher();
        $this->stock = new StockService($levels, $stockrooms, $this->events);
        $this->stockrooms = new StockroomService($stockrooms, $levels);
        $this->sofia = $this->stockrooms->create(new SaveStockroom('Sofia', 1, 2))->id;
        $this->berlin = $this->stockrooms->create(new SaveStockroom('Berlin', 3, 7))->id;
    }

    public function testTheSheetChangesSingleCellsOfManyItems(): void
    {
        $this->stock->setLevels(StockItemRef::product(10), new SetStockLevels([new SetStockLevel($this->sofia, 4), new SetStockLevel($this->berlin, 2)]));
        $this->events->events = [];

        $this->stock->changeLevels([
            new ChangeStockLevel('product', 10, $this->sofia, 6),
            new ChangeStockLevel('product', 10, $this->berlin, null),
            new ChangeStockLevel('variation', 20, $this->berlin, 0),
        ]);

        self::assertSame(
            ['product:10' => [$this->sofia => 6], 'variation:20' => [$this->berlin => 0], 'option:30' => []],
            $this->stock->levelsOf([StockItemRef::product(10), StockItemRef::variation(20), StockItemRef::option(30)]),
        );
        self::assertSame(0, $this->stock->available([StockItemRef::variation(20)]), 'a level of 0 counts: none left');
        self::assertCount(2, $this->events->events);
    }

    public function testASheetWithOneBadCellChangesNothing(): void
    {
        $this->stock->setLevels(StockItemRef::product(10), new SetStockLevels([new SetStockLevel($this->sofia, 4)]));

        foreach ([
            [[new ChangeStockLevel('product', 10, $this->sofia, 9), new ChangeStockLevel('product', 10, 999, 1)], 'orillacart_unknown_stockroom'],
            [[new ChangeStockLevel('product', 10, $this->sofia, 9), new ChangeStockLevel('product', 10, $this->sofia, 8)], 'orillacart_duplicate_stockroom'],
        ] as [$changes, $code]) {
            try {
                $this->stock->changeLevels($changes);
                self::fail('Refused: ' . $code);
            } catch (InventoryRuleViolation $e) {
                self::assertSame($code, $e->errorCode);
            }
        }
        self::assertSame(['product:10' => [$this->sofia => 4]], $this->stock->levelsOf([StockItemRef::product(10)]));
    }

    public function testDeliveryTimesInWeeksAreWholeWeeksKeptInDays(): void
    {
        $saved = $this->stockrooms->create(new SaveStockroom('Warehouse', 7, 21, deliveryUnit: 'weeks'));
        self::assertSame([7, 21, 'weeks'], [$saved->minDeliveryDays, $saved->maxDeliveryDays, $saved->deliveryUnit]);

        $this->expectException(InventoryRuleViolation::class);
        $this->stockrooms->create(new SaveStockroom('Warehouse', 7, 10, deliveryUnit: 'weeks'));
    }

    public function testSettingLevelsReplacesThemAll(): void
    {
        $mug = StockItemRef::product(5);

        $this->stock->setLevels($mug, new SetStockLevels([new SetStockLevel($this->sofia, 4), new SetStockLevel($this->berlin, 2)]));
        $view = $this->stock->setLevels($mug, new SetStockLevels([new SetStockLevel($this->berlin, 3)]));

        self::assertSame('product:5', $view->item);
        self::assertSame(3, $view->available);
        self::assertCount(1, $view->levels);
        self::assertSame([$this->berlin, 3], [$view->levels[0]->stockroomId, $view->levels[0]->quantity]);
        self::assertNull($this->stock->setLevels($mug, new SetStockLevels([]))->available, 'no levels: not limited');
        self::assertEquals(
            [new StockChanged('product:5', null, 6), new StockChanged('product:5', 6, 3), new StockChanged('product:5', 3, null)],
            $this->events->events,
            'how many could be sold before and after each change',
        );
    }

    public function testOnlyRunningOutOrComingBackChangesAvailability(): void
    {
        self::assertFalse(new StockChanged('product:5', 10, 8)->changedAvailability(), 'selling some');
        self::assertTrue(new StockChanged('product:5', 1, 0)->changedAvailability(), 'selling the last');
        self::assertTrue(new StockChanged('product:5', 0, 2)->changedAvailability(), 'restocked');
        self::assertFalse(new StockChanged('product:5', null, 4)->changedAvailability(), 'counted from now on, still for sale');
        self::assertTrue(new StockChanged('product:5', null, 0)->changedAvailability(), 'counted from now on, none');
        self::assertFalse(new StockChanged('product:5', -2, -3)->changedAvailability(), 'owed more on backorder');
    }

    public function testLevelsNeedAnExistingStockroomEach(): void
    {
        foreach ([[new SetStockLevel(99, 1)], [new SetStockLevel($this->sofia, 1), new SetStockLevel($this->sofia, 2)]] as $levels) {
            try {
                $this->stock->setLevels(StockItemRef::product(5), new SetStockLevels($levels));
                self::fail('The levels were accepted.');
            } catch (InventoryRuleViolation) {
                self::assertNull($this->stock->stock(StockItemRef::product(5))->available);
            }
        }
    }

    public function testItemsSoldTogetherAreLimitedByTheScarcest(): void
    {
        $this->stock->setLevels(StockItemRef::product(5), new SetStockLevels([new SetStockLevel($this->sofia, 9)]));
        $this->stock->setLevels(StockItemRef::option(3), new SetStockLevels([new SetStockLevel($this->berlin, 2)]));

        self::assertSame(2, $this->stock->available([StockItemRef::product(5), StockItemRef::option(3), StockItemRef::option(4)]));
        self::assertSame(9, $this->stock->available([StockItemRef::product(5)]));
        self::assertNull($this->stock->available([StockItemRef::option(4)]));
    }

    public function testTakingIsAllOrNothing(): void
    {
        $this->stock->setLevels(StockItemRef::product(5), new SetStockLevels([new SetStockLevel($this->sofia, 9)]));
        $this->stock->setLevels(StockItemRef::option(3), new SetStockLevels([new SetStockLevel($this->berlin, 2)]));

        try {
            $this->stock->take([StockItemRef::product(5), StockItemRef::option(3)], 3);
            self::fail('Three were taken from two.');
        } catch (InsufficientStock) {
            self::assertSame(9, $this->stock->stock(StockItemRef::product(5))->available);
        }

        self::assertEquals(
            [new TakenStock('product:5', $this->sofia, 2), new TakenStock('option:3', $this->berlin, 2)],
            $this->stock->take([StockItemRef::product(5), StockItemRef::option(3), StockItemRef::option(4)], 2),
        );
        self::assertSame(0, $this->stock->available([StockItemRef::option(3)]));
    }

    public function testBackordersTakeWhatIsInStockAndOweTheRest(): void
    {
        $this->stock->setLevels(StockItemRef::product(5), new SetStockLevels([new SetStockLevel($this->sofia, 1)]));
        $this->stock->setLevels(StockItemRef::option(3), new SetStockLevels([new SetStockLevel($this->berlin, 5)]));

        $taken = $this->stock->take([StockItemRef::product(5), StockItemRef::option(3)], 3, backorders: true);

        self::assertEquals([new TakenStock('product:5', $this->sofia, 3, 2), new TakenStock('option:3', $this->berlin, 3, 0)], $taken);
        self::assertSame(0, $this->stock->available([StockItemRef::product(5)]), 'nothing to sell, two owed');
        self::assertSame([[$this->sofia, -2]], array_map(static fn($level): array => [$level->stockroomId, $level->quantity], $this->stock->stock(StockItemRef::product(5))->levels));

        $this->stock->giveBack($taken);
        self::assertSame(1, $this->stock->available([StockItemRef::product(5)]), 'a cancelled backorder owes nothing');
    }

    public function testDeletingAStockroomDeletesItsStock(): void
    {
        $this->stock->setLevels(StockItemRef::product(5), new SetStockLevels([new SetStockLevel($this->sofia, 9), new SetStockLevel($this->berlin, 1)]));

        $this->stockrooms->delete($this->sofia);
        $this->stock->removeItems([StockItemRef::option(3)]);

        self::assertSame(1, $this->stock->available([StockItemRef::product(5)]));
        self::assertSame(['Berlin'], array_map(static fn($stockroom): string => $stockroom->name, $this->stockrooms->stockrooms()));
        $this->stock->removeItems([StockItemRef::product(5)]);
        self::assertNull($this->stock->available([StockItemRef::product(5)]));
    }

    public function testStockOfACancelledOrderGoesBackWhereItCameFrom(): void
    {
        $mug = StockItemRef::product(5);
        $this->stock->setLevels($mug, new SetStockLevels([new SetStockLevel($this->sofia, 2), new SetStockLevel($this->berlin, 5)]));
        $taken = $this->stock->take([$mug], 4);
        self::assertSame(3, $this->stock->stock($mug)->available);

        // Berlin's level was removed meanwhile: it comes back as a new level.
        $this->stock->setLevels($mug, new SetStockLevels([new SetStockLevel($this->sofia, 0)]));
        $this->stock->giveBack($taken);

        self::assertSame([[$this->sofia, 2], [$this->berlin, 2]], array_map(static fn($level): array => [$level->stockroomId, $level->quantity], $this->stock->stock($mug)->levels));
    }

    public function testStockOfADeletedStockroomIsNotPutBack(): void
    {
        $mug = StockItemRef::product(5);
        $this->stock->setLevels($mug, new SetStockLevels([new SetStockLevel($this->berlin, 5), new SetStockLevel($this->sofia, 0)]));
        $taken = $this->stock->take([$mug], 2);
        $this->stockrooms->delete($this->berlin);

        $this->stock->giveBack([...$taken, new TakenStock('product:5', $this->sofia, 1)]);

        self::assertSame([[$this->sofia, 1]], array_map(static fn($level): array => [$level->stockroomId, $level->quantity], $this->stock->stock($mug)->levels));
    }

    public function testAStockroomThatAloneHoldsItemsIsTurnedOffNotDeleted(): void
    {
        $this->stock->setLevels(StockItemRef::product(5), new SetStockLevels([new SetStockLevel($this->berlin, 3)]));
        $this->stock->setLevels(StockItemRef::product(6), new SetStockLevels([new SetStockLevel($this->berlin, 1), new SetStockLevel($this->sofia, 1)]));

        try {
            $this->stockrooms->delete($this->berlin);
            self::fail('product 5 would sell without limit');
        } catch (InventoryRuleViolation $e) {
            self::assertSame('orillacart_stockroom_in_use', $e->errorCode);
            self::assertStringContainsString('Berlin is the only stockroom holding 1 item(s)', $e->getMessage());
        }
        self::assertSame(3, $this->stock->available([StockItemRef::product(5)]));
    }

    public function testCountedItemsNoStockroomHoldsStartAtNothing(): void
    {
        $this->stock->setLevels(StockItemRef::product(6), new SetStockLevels([new SetStockLevel($this->berlin, 4)]));
        $this->events->events = [];

        $this->stock->countFromNothing([StockItemRef::product(5), StockItemRef::product(6)]);

        self::assertSame(0, $this->stock->available([StockItemRef::product(5)]), 'new: 0, not without limit');
        self::assertSame([[$this->sofia, 0]], array_map(static fn($level): array => [$level->stockroomId, $level->quantity], $this->stock->stock(StockItemRef::product(5))->levels));
        self::assertSame(4, $this->stock->available([StockItemRef::product(6)]), 'one counted already is left as it is');
        self::assertCount(1, $this->events->events, 'the new one is announced: it is no longer for sale');
    }
}
