<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Exchange;

use OrillaCart\Catalog\Application\Dto\SaveAttribute;
use OrillaCart\Catalog\Application\Dto\SaveOption;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Catalog\Application\Dto\SaveVariation;
use OrillaCart\Catalog\Application\ProductLookup;
use OrillaCart\Exchange\Application\ColumnChoice;
use OrillaCart\Exchange\Application\CsvFile;
use OrillaCart\Exchange\Application\Dto\ChooseColumns;
use OrillaCart\Exchange\Application\Dto\ProductPost;
use OrillaCart\Exchange\Application\ProductColumns;
use OrillaCart\Exchange\Application\ProductsExport;
use OrillaCart\Extension\Catalog\ProductColumn;
use OrillaCart\Extension\Catalog\ProductItem;
use OrillaCart\Inventory\Application\Dto\SaveStockroom;
use OrillaCart\Inventory\Application\Dto\SetStockLevel;
use OrillaCart\Inventory\Application\Dto\SetStockLevels;
use OrillaCart\Inventory\Application\Dto\StockItemRef;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use OrillaCart\Tests\Fixtures\Exchange\EnglishColumnNames;
use OrillaCart\Tests\Fixtures\Exchange\InMemoryExportChoices;
use OrillaCart\Tests\Fixtures\Exchange\InMemoryProductPosts;
use PHPUnit\Framework\TestCase;

final class ProductsExportTest extends TestCase
{
    private TestShop $shop;
    private InMemoryProductPosts $posts;
    private ProductsExport $export;
    private int $room;

    protected function setUp(): void
    {
        $this->shop = new TestShop();
        $this->room = $this->shop->stockrooms->create(new SaveStockroom('Sofia'))->id;
        $this->posts = new InMemoryProductPosts();
        $this->export = new ProductsExport(
            new ProductColumns($this->shop->extensions, $this->shop->stockrooms, $this->shop->logger),
            new ColumnChoice(new InMemoryExportChoices(), new EnglishColumnNames()),
            new ProductLookup($this->shop->productRecords, $this->shop->content),
            $this->shop->products,
            $this->posts,
            $this->shop->stock,
            $this->shop->logger,
        );
    }

    public function testEachProductAndEachOfItsVariationsIsARowNamedByColumnIds(): void
    {
        $this->shop->content->add(10, 'Lamp');
        $this->shop->products->save(10, new SaveProduct('30', sku: 'LAMP', weight: '2'));
        $this->shop->stock->setLevels(StockItemRef::product(10), new SetStockLevels([new SetStockLevel($this->room, 4)]));
        $this->posts->posts[10] = new ProductPost('draft', '<p>Brass</p>', 'Bright', ['Lighting > Desk lamps'], ['Orilla'], ['brass', 'gift']);
        $this->shop->content->add(11, 'Hoodie');
        $hoodie = $this->shop->products->save(11, new SaveProduct('40', attributes: [new SaveAttribute('Size', [new SaveOption('S'), new SaveOption('M')])]));
        $small = $this->shop->products->addVariation(11, new SaveVariation([$hoodie->attributes[0]->options[0]->id], '41', sku: 'HOOD-S'));
        $this->shop->stock->setLevels(StockItemRef::variation($small->id), new SetStockLevels([new SetStockLevel($this->room, 2)]));
        $this->shop->extensions->productColumns[] = new class implements ProductColumn {
            public function id(): string
            {
                return 'barcode';
            }

            public function label(): string
            {
                return 'Barcode';
            }

            public function value(ProductItem $item): string
            {
                return 'B' . ($item->variationId ?? $item->productId);
            }
        };
        $this->export->choose(new ChooseColumns(['id', 'variation_id', 'name', 'status', 'sku', 'price', 'categories', 'tags', 'options', "stock_{$this->room}", 'barcode']));

        self::assertSame([
            ['id', 'variation_id', 'name', 'status', 'sku', 'price', 'categories', 'tags', 'options', "stock_{$this->room}", 'barcode'],
            ['10', '', 'Lamp', 'draft', 'LAMP', '30', 'Lighting > Desk lamps', 'brass | gift', '', '4', 'B10'],
            ['11', '', 'Hoodie', 'publish', '', '40', '', '', 'Size: S, M', '', 'B11'],
            ['11', (string) $small->id, 'Hoodie – S', '', 'HOOD-S', '41', '', '', 'S', '2', 'B' . $small->id],
        ], $this->csv());
    }

    public function testEveryStockroomHasItsColumnAndTheShopsColumnsAreImportable(): void
    {
        $columns = [];
        foreach ($this->export->columns() as $column) {
            $columns[$column->id] = [$column->label, $column->importable];
        }

        self::assertSame(['Stock: Sofia', true], $columns["stock_{$this->room}"]);
        self::assertSame(['Product ID', false], $columns['id'], 'it names the row; it is not changed');
        self::assertSame(['Price', true], $columns['price']);
        self::assertFalse($columns['options'][1], 'variations are made on the product screen');
    }

    /** @return list<list<string>> */
    private function csv(): array
    {
        $stream = fopen('php://memory', 'w+b');
        self::assertIsResource($stream);
        $this->export->export(new CsvFile($stream, headerIds: true));
        rewind($stream);
        $rows = [];
        $content = substr((string) stream_get_contents($stream), \strlen("\u{FEFF}"));
        $handle = fopen('php://memory', 'w+b');
        self::assertIsResource($handle);
        fwrite($handle, $content);
        rewind($handle);
        while (($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
            $rows[] = array_map(strval(...), $row);
        }

        return $rows;
    }
}
