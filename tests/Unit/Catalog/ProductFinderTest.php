<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Catalog;

use OrillaCart\Catalog\Application\AddOnProducts;
use OrillaCart\Catalog\Application\Dto\ProductFilter;
use OrillaCart\Catalog\Application\Dto\SaveAttribute;
use OrillaCart\Catalog\Application\Dto\SaveBundle;
use OrillaCart\Catalog\Application\Dto\SaveBundleItem;
use OrillaCart\Catalog\Application\Dto\SaveOption;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Catalog\Application\Dto\SaveSale;
use OrillaCart\Catalog\Application\Dto\SaveVariation;
use OrillaCart\Catalog\Application\ProductFinder;
use OrillaCart\Catalog\Domain\ProductCriteria;
use OrillaCart\Extension\Catalog\FindsProducts;
use OrillaCart\Extension\Catalog\ProductFilter as AddOnFilter;
use OrillaCart\Inventory\Application\Dto\SaveStockroom;
use OrillaCart\Inventory\Application\Dto\SetStockLevel;
use OrillaCart\Inventory\Application\Dto\SetStockLevels;
use OrillaCart\Inventory\Application\Dto\StockItemRef;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProductFinderTest extends TestCase
{
    private const int CAMERA = 10;
    private const int BATTERY = 20;
    private const int SHIRT = 30;
    private const int POSTER = 40;
    private const int EBOOK = 50;
    private const int KIT = 60;
    private const int GIFT = 70;
    private const int SPEAKER = 80;

    private TestShop $shop;
    private int $sofia;
    private int $plovdiv;
    /** @var array{int, int} the shirt's small and large */
    private array $sizes;

    protected function setUp(): void
    {
        $this->shop = new TestShop();
        $this->sofia = $this->shop->stockrooms->create(new SaveStockroom('Sofia'))->id;
        $this->plovdiv = $this->shop->stockrooms->create(new SaveStockroom('Plovdiv'))->id;
        $this->product(self::CAMERA, new SaveProduct('300', sku: 'CAM-1', weight: '1.2', featured: true), [$this->sofia => 5]);
        $this->product(self::BATTERY, new SaveProduct('20', sku: 'BAT', weight: '0.1', sale: new SaveSale('15')), [$this->sofia => 1]);
        $this->product(self::SHIRT, new SaveProduct('25', sku: 'SHIRT', attributes: [new SaveAttribute('Size', [new SaveOption('S'), new SaveOption('L')], required: true)]), []);
        $options = $this->shop->productRecords->find(self::SHIRT)?->attributes()[0]->options() ?? [];
        $this->sizes = [
            $this->shop->products->addVariation(self::SHIRT, new SaveVariation([$options[0]->id()], '25', sku: 'SHIRT-S'))->id,
            $this->shop->products->addVariation(self::SHIRT, new SaveVariation([$options[1]->id()], '45'))->id,
        ];
        $this->shop->stock->setLevels(StockItemRef::variation($this->sizes[0]), new SetStockLevels([new SetStockLevel($this->plovdiv, 4)]));
        // As the shop starts a new counted variation (StartCountingStock): at 0, not without limit.
        $this->shop->stock->countFromNothing([StockItemRef::variation($this->sizes[1])]);
        $this->product(self::POSTER, new SaveProduct('0', priceVisibility: 'hidden', purchasable: false), [$this->sofia => 0]);
        $this->product(self::EBOOK, new SaveProduct('9', 'virtual', sku: 'BOOK', stockMode: 'always_available'), []);
        $this->product(self::GIFT, new SaveProduct('30', 'virtual', sku: 'GIFT', stockMode: 'unavailable'), []);
        // Sold on backorder: Sofia owes two.
        $this->product(self::SPEAKER, new SaveProduct('60', sku: 'SPK', weight: '2'), [$this->sofia => 0]);
        $this->shop->stock->take([StockItemRef::product(self::SPEAKER)], 2, backorders: true);
        // Two batteries to a kit: there is one.
        $this->product(self::KIT, new SaveProduct('0', 'virtual', sku: 'KIT', bundle: new SaveBundle([new SaveBundleItem(self::CAMERA, 1), new SaveBundleItem(self::BATTERY, 2)], 'sum')), []);
    }

    /**
     * @return iterable<string, array{ProductFilter, list<int>|null}>
     */
    public static function filters(): iterable
    {
        yield 'nothing narrows' => [new ProductFilter(), null];
        yield 'ships' => [new ProductFilter(kind: ProductCriteria::SHIPS), [self::CAMERA, self::BATTERY, self::SHIRT, self::POSTER, self::SPEAKER]];
        yield 'does not ship' => [new ProductFilter(kind: ProductCriteria::DOES_NOT_SHIP), [self::EBOOK, self::KIT, self::GIFT]];
        yield 'with variations' => [new ProductFilter(kind: ProductCriteria::WITH_VARIATIONS), [self::SHIRT]];
        yield 'bundles' => [new ProductFilter(kind: ProductCriteria::BUNDLE), [self::KIT]];
        yield 'featured' => [new ProductFilter(shown: ProductCriteria::FEATURED), [self::CAMERA]];
        yield 'on sale' => [new ProductFilter(shown: ProductCriteria::ON_SALE), [self::BATTERY]];
        yield 'price hidden' => [new ProductFilter(shown: ProductCriteria::PRICE_HIDDEN), [self::POSTER]];
        yield 'not purchasable' => [new ProductFilter(shown: ProductCriteria::NOT_PURCHASABLE), [self::POSTER]];
        yield 'the sale price is charged' => [new ProductFilter(minPrice: '10', maxPrice: '15'), [self::BATTERY]];
        yield 'a variation in range' => [new ProductFilter(minPrice: '40', maxPrice: '50'), [self::SHIRT]];
        yield 'missing a SKU, a variation too' => [new ProductFilter(missing: ProductCriteria::NO_SKU), [self::SHIRT, self::POSTER]];
        yield 'missing a price, not a bundle priced by its items' => [new ProductFilter(missing: ProductCriteria::NO_PRICE), [self::POSTER]];
        yield 'missing a weight' => [new ProductFilter(missing: ProductCriteria::NO_WEIGHT), [self::SHIRT, self::POSTER]];
        yield 'in stock' => [new ProductFilter(stock: ProductFinder::IN_STOCK), [self::CAMERA, self::BATTERY, self::SHIRT, self::EBOOK]];
        yield 'some variations out' => [new ProductFilter(stock: ProductFinder::SOME_OUT), [self::SHIRT]];
        yield 'out of stock, the kit short of batteries' => [new ProductFilter(stock: ProductFinder::OUT_OF_STOCK), [self::POSTER, self::KIT, self::SPEAKER]];
        yield 'owed' => [new ProductFilter(stock: ProductFinder::OWED), [self::SPEAKER]];
        yield 'always available' => [new ProductFilter(stock: ProductFinder::ALWAYS_AVAILABLE), [self::EBOOK]];
        yield 'not for sale' => [new ProductFilter(stock: ProductFinder::NOT_FOR_SALE), [self::GIFT]];
        yield 'held in a stockroom' => [new ProductFilter(stockroom: 2), [self::SHIRT]];
        yield 'in stock in a stockroom' => [new ProductFilter(stock: ProductFinder::IN_STOCK, stockroom: 1), [self::CAMERA, self::BATTERY]];
        yield 'filters add up' => [new ProductFilter(kind: ProductCriteria::SHIPS, stock: ProductFinder::IN_STOCK, missing: ProductCriteria::NO_WEIGHT), [self::SHIRT]];
    }

    /**
     * @param list<int>|null $expected
     */
    #[DataProvider('filters')]
    public function testFindsTheProductsTheFilterNarrowsTo(ProductFilter $filter, ?array $expected): void
    {
        self::assertSame($expected, $this->shop->productFinder->ids($filter));
    }

    public function testLowStockIsTheStockPagesAndABundleWithALowItemIsToo(): void
    {
        self::assertSame([self::BATTERY, self::SHIRT, self::POSTER, self::KIT, self::SPEAKER], $this->shop->productFinder->ids(new ProductFilter(stock: ProductFinder::LOW_STOCK)), 'the shirt\'s L, at 0, too');
    }

    public function testABundleSellsWhileOneVariationOfAChoiceDoesAndOptionalItemsDoNotHoldItBack(): void
    {
        $this->shop->products->save(self::KIT, new SaveProduct('0', 'virtual', bundle: new SaveBundle([
            new SaveBundleItem(self::CAMERA, 1),
            new SaveBundleItem(self::SHIRT, 1, $this->sizes),
            new SaveBundleItem(self::BATTERY, 2, optional: true),
        ], 'sum')));

        self::assertContains(self::KIT, $this->shop->productFinder->ids(new ProductFilter(stock: ProductFinder::IN_STOCK)) ?? []);
        self::assertContains(self::KIT, $this->shop->productFinder->ids(new ProductFilter(stock: ProductFinder::SOME_OUT)) ?? [], 'the large shirt and the batteries are out');
    }

    public function testAnAddOnsFilterListsTheProductsItMarkedAndItsSearchFindsByItsOwnText(): void
    {
        $this->shop->extensions->productFilters = [new class implements AddOnFilter {
            public function id(): string
            {
                return 'marketplace';
            }

            public function label(): string
            {
                return 'Listed on the marketplace';
            }
        }];
        $this->shop->extensions->productSearches = [
            new class implements FindsProducts {
                public function id(): string
                {
                    return 'barcodes';
                }

                public function findProducts(string $text): array
                {
                    return $text === '3800123' ? [40, 10, 40] : [];
                }
            },
            new class implements FindsProducts {
                public function id(): string
                {
                    return 'odd';
                }

                public function findProducts(string $text): array
                {
                    return [0];
                }
            },
        ];
        $products = new AddOnProducts($this->shop->productRecords);
        $finder = $this->shop->productFinder;

        self::assertTrue($products->mark(self::CAMERA, 'marketplace'));
        self::assertTrue($products->mark(self::SHIRT, 'marketplace'));
        self::assertFalse($products->mark(999, 'marketplace'), 'no such product');
        self::assertSame(['marketplace' => 'Listed on the marketplace'], $finder->addOnFilters());
        self::assertSame([self::CAMERA, self::SHIRT], $finder->ids(new ProductFilter(addOn: 'marketplace')));
        self::assertSame([self::CAMERA], $finder->ids(new ProductFilter(stock: ProductFinder::IN_STOCK, shown: ProductCriteria::FEATURED, addOn: 'marketplace')));
        $products->unmark(self::CAMERA, 'marketplace');
        self::assertSame([self::SHIRT], $finder->ids(new ProductFilter(addOn: 'marketplace')));

        self::assertSame([self::CAMERA, self::POSTER], $finder->matching('3800123'), 'each once; the odd answer finds nothing');
        self::assertSame([self::BATTERY], $finder->matching('BAT'), 'by SKU, as before');
        self::assertContains('The product search odd', array_map(static fn(array $record): mixed => $record['context']['what'] ?? null, $this->shop->logger->records));
    }

    public function testAProductNotAvailableSellsNoneOfItsVariations(): void
    {
        $this->product(90, new SaveProduct('25', attributes: [new SaveAttribute('Size', [new SaveOption('S')], required: true)], stockMode: 'unavailable'), []);
        $small = $this->shop->productRecords->find(90)?->attributes()[0]->options()[0]->id() ?? 0;
        $variation = $this->shop->products->addVariation(90, new SaveVariation([$small], '25'))->id;
        $this->shop->stock->setLevels(StockItemRef::variation($variation), new SetStockLevels([new SetStockLevel($this->sofia, 3)]));

        self::assertContains(90, $this->shop->productFinder->ids(new ProductFilter(stock: ProductFinder::NOT_FOR_SALE)) ?? []);
        self::assertNotContains(90, $this->shop->productFinder->ids(new ProductFilter(stock: ProductFinder::IN_STOCK)) ?? []);
        $offer = $this->shop->catalog->offer(90);
        self::assertFalse($offer->forSale);
        self::assertSame(0, $offer->variations[0]->available, 'three in Sofia, none for sale');
    }

    public function testNoBackordersWhenOnlyAStockroomThatIsOffHoldsTheItem(): void
    {
        $this->product(91, new SaveProduct('40', sku: 'LAMP', backorders: 'yes'), [$this->plovdiv => 0]);
        self::assertTrue($this->shop->catalog->offer(91)->forSale, 'Plovdiv owes it');

        $this->shop->stockrooms->update($this->plovdiv, new SaveStockroom('Plovdiv', active: false));

        $offer = $this->shop->catalog->offer(91);
        self::assertFalse($offer->forSale, 'no stockroom could owe it: checkout would fail');
        self::assertSame(0, $offer->available);
        self::assertFalse($this->shop->catalog->offers([91])[91]->forSale, 'in lists too');
    }

    /**
     * @param array<int, int> $stock by stockroom
     */
    private function product(int $id, SaveProduct $product, array $stock): void
    {
        $this->shop->content->add($id, 'Product ' . $id);
        $this->shop->products->save($id, $product);
        if ($stock !== []) {
            $this->shop->stock->setLevels(StockItemRef::product($id), new SetStockLevels(array_map(static fn(int $room, int $quantity): SetStockLevel => new SetStockLevel($room, $quantity), array_keys($stock), $stock)));
        }
    }
}
