<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Catalog;

use OrillaCart\Catalog\Application\BulkEdits;
use OrillaCart\Catalog\Application\Dto\BulkEdit;
use OrillaCart\Catalog\Application\Dto\SaveAttribute;
use OrillaCart\Catalog\Application\Dto\SaveOption;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Catalog\Application\Dto\SaveSale;
use OrillaCart\Catalog\Application\Dto\SaveVariation;
use OrillaCart\Catalog\Domain\CatalogRuleViolation;
use OrillaCart\Inventory\Application\Dto\SaveStockroom;
use OrillaCart\Inventory\Application\Dto\SetStockLevel;
use OrillaCart\Inventory\Application\Dto\SetStockLevels;
use OrillaCart\Inventory\Application\Dto\StockItemRef;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use PHPUnit\Framework\TestCase;

final class BulkEditsTest extends TestCase
{
    private TestShop $shop;
    private BulkEdits $edits;
    private int $room;
    private int $small;

    protected function setUp(): void
    {
        $this->shop = new TestShop();
        $this->edits = new BulkEdits($this->shop->products, $this->shop->stock, $this->shop->settingsService, $this->shop->transactions);
        $this->room = $this->shop->stockrooms->create(new SaveStockroom('Sofia'))->id;
        $this->shop->content->add(10, 'Lamp');
        $this->shop->products->save(10, new SaveProduct('30', sale: new SaveSale('25', new \DateTimeImmutable('2026-10-01T00:00:00+00:00'))));
        $this->shop->stock->setLevels(StockItemRef::product(10), new SetStockLevels([new SetStockLevel($this->room, 4)]));
        $this->shop->content->add(11, 'Hoodie');
        $hoodie = $this->shop->products->save(11, new SaveProduct('40', attributes: [new SaveAttribute('Size', [new SaveOption('S')])]));
        $this->small = $this->shop->products->addVariation(11, new SaveVariation([$hoodie->attributes[0]->options[0]->id], '41'))->id;
    }

    public function testPricesRiseByAPercentageInTheShopsDecimalsVariationsToo(): void
    {
        self::assertSame([], $this->edits->apply([10, 11], new BulkEdit(price: 'raise_percent', priceBy: '10', sale: 'percent_off', saleBy: '20')));

        $lamp = $this->shop->products->product(10);
        self::assertSame(['33', '26.4'], [$lamp->price, $lamp->sale?->price]);
        self::assertEquals(new \DateTimeImmutable('2026-10-01T00:00:00+00:00'), $lamp->sale?->from, 'the sale keeps its dates');
        $hoodie = $this->shop->products->product(11);
        self::assertSame(['44', '45.1', '36.08'], [$hoodie->price, $hoodie->variations[0]->price, $hoodie->variations[0]->sale?->price]);
    }

    public function testStockAddedInOneStockroomLeavesWhatAnotherOwes(): void
    {
        $berlin = $this->shop->stockrooms->create(new SaveStockroom('Berlin'))->id;
        $this->shop->stock->take([StockItemRef::product(10)], 6, backorders: true);
        self::assertSame([$this->room => -2], $this->shop->stock->levelsOf([StockItemRef::product(10)])['product:10'], 'Sofia owes two');

        self::assertSame([], $this->edits->apply([10], new BulkEdit(stock: 'add', stockroomId: $berlin, stockBy: 3)));

        self::assertSame([$this->room => -2, $berlin => 3], $this->shop->stock->levelsOf([StockItemRef::product(10)])['product:10'], 'still owed: the next ones that come in go to those customers');
    }

    public function testAPriceLoweredToItsSaleIsRefusedForThatProduct(): void
    {
        self::assertSame(['Lamp: A sale price is below the regular price: 25 is not below 24. Lower the sale too, or remove it.'], array_map(strval(...), $this->edits->apply([10], new BulkEdit(price: 'lower_percent', priceBy: '20'))));
        self::assertSame(['30', '25'], [$this->shop->products->product(10)->price, $this->shop->products->product(10)->sale?->price], 'customers would have paid the sale, above the price');

        self::assertSame([], $this->edits->apply([10], new BulkEdit(price: 'lower_percent', priceBy: '20', sale: 'remove')));
        self::assertSame(['24', null], [$this->shop->products->product(10)->price, $this->shop->products->product(10)->sale]);
    }

    public function testStockIsAddedInOneStockroomAndAProductThatCannotTakeItIsNamed(): void
    {
        self::assertSame(['Hoodie: the stock would be below zero.'], array_map(strval(...), $this->edits->apply([10, 11], new BulkEdit(price: 'lower', priceBy: '4', stock: 'add', stockroomId: $this->room, stockBy: -3))));

        self::assertSame([$this->room => 1], $this->shop->stock->levelsOf([StockItemRef::product(10)])['product:10']);
        self::assertSame('26', $this->shop->products->product(10)->price);
        self::assertSame('40', $this->shop->products->product(11)->price, 'the hoodie is left as it was, its price too');
        self::assertSame([], $this->shop->stock->levelsOf([StockItemRef::variation($this->small)])["variation:{$this->small}"] ?? []);

        $this->edits->apply([11], new BulkEdit(stock: 'set', stockroomId: $this->room, stockBy: 6, sale: 'remove'));
        self::assertSame([$this->room => 6], $this->shop->stock->levelsOf([StockItemRef::variation($this->small)])["variation:{$this->small}"]);
    }

    public function testAChangeThatIsNotOneIsRefused(): void
    {
        $this->expectException(CatalogRuleViolation::class);

        $this->edits->apply([10], new BulkEdit(price: 'raise', priceBy: 'lots'));
    }
}
