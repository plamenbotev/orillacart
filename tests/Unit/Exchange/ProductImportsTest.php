<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Exchange;

use OrillaCart\Catalog\Application\Dto\SaveAttribute;
use OrillaCart\Catalog\Application\Dto\SaveOption;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Catalog\Application\Dto\SaveVariation;
use OrillaCart\Catalog\Application\ProductLookup;
use OrillaCart\Exchange\Application\ColumnChoice;
use OrillaCart\Exchange\Application\Dto\ColumnView;
use OrillaCart\Exchange\Application\Dto\ImportView;
use OrillaCart\Exchange\Application\Dto\ProductPostChange;
use OrillaCart\Exchange\Application\ImportProducts;
use OrillaCart\Exchange\Application\ProductColumns;
use OrillaCart\Exchange\Application\ProductImports;
use OrillaCart\Exchange\Application\ProductRows;
use OrillaCart\Exchange\Domain\ExchangeRuleViolation;
use OrillaCart\Extension\Catalog\ImportsProductColumn;
use OrillaCart\Extension\Catalog\ProductItem;
use OrillaCart\Inventory\Application\Dto\SaveStockroom;
use OrillaCart\Inventory\Application\Dto\SetStockLevel;
use OrillaCart\Inventory\Application\Dto\SetStockLevels;
use OrillaCart\Inventory\Application\Dto\StockItemRef;
use OrillaCart\Jobs\Application\Dto\JobOutcome;
use OrillaCart\Jobs\Application\Dto\QueuedJob;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use OrillaCart\Tests\Fixtures\Exchange\EnglishColumnNames;
use OrillaCart\Tests\Fixtures\Exchange\InMemoryExportChoices;
use OrillaCart\Tests\Fixtures\Exchange\InMemoryProductImports;
use OrillaCart\Tests\Fixtures\Exchange\InMemoryProductPosts;
use OrillaCart\Tests\Fixtures\Jobs\RecordedProgress;
use PHPUnit\Framework\TestCase;

final class ProductImportsTest extends TestCase
{
    private TestShop $shop;
    private InMemoryProductPosts $posts;
    private InMemoryProductImports $records;
    private ProductImports $imports;
    private int $sofia;
    private int $plovdiv;
    /** @var array<int, string> */
    private array $barcodes = [];

    protected function setUp(): void
    {
        $this->shop = new TestShop();
        $this->sofia = $this->shop->stockrooms->create(new SaveStockroom('Sofia'))->id;
        $this->plovdiv = $this->shop->stockrooms->create(new SaveStockroom('Plovdiv'))->id;
        $this->posts = new InMemoryProductPosts($this->shop->content);
        $this->records = new InMemoryProductImports();
        $columns = new ProductColumns($this->shop->extensions, $this->shop->stockrooms, $this->shop->logger);
        $rows = new ProductRows($this->shop->products, new ProductLookup($this->shop->productRecords, $this->shop->content), $this->posts, $this->shop->stock, $columns);
        $this->imports = new ProductImports($this->records, $columns, new ColumnChoice(new InMemoryExportChoices(), new EnglishColumnNames()), $rows, $this->shop->files, $this->shop->jobs->jobs, $this->shop->clock, $this->shop->logger);
        $this->shop->jobs->with(new ImportProducts($this->imports, $this->shop->clock));
        $barcodes = &$this->barcodes;
        $this->shop->extensions->productColumns[] = new class ($barcodes) implements ImportsProductColumn {
            /** @param array<int, string> $barcodes */
            public function __construct(private array &$barcodes) {}

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
                return $this->barcodes[$item->variationId ?? $item->productId] ?? '';
            }

            public function import(ProductItem $item, string $value): void
            {
                if ($value !== '' && !ctype_digit($value)) {
                    throw new \InvalidArgumentException('a barcode is digits');
                }
                $this->barcodes[$item->variationId ?? $item->productId] = $value;
            }
        };
    }

    public function testTheUploadSaysWhatTheFileChangesBeforeItStarts(): void
    {
        $view = $this->upload("\u{FEFF}id,SKU,price,options,colour,stock_{$this->sofia},barcode\n10,L,30,,red,4,\n\n11,M,40,,blue,,\n");

        self::assertSame(['ready', 2], [$view->status, $view->rows], 'blank rows do not count');
        self::assertSame(['id', 'sku', 'price', "stock_{$this->sofia}", 'barcode'], array_map(static fn(ColumnView $column): string => $column->id, $view->columns));
        self::assertSame(['options', 'colour'], $view->ignored, 'only exported, or unknown');
        self::assertSame(0, $this->shop->jobs->jobs->pending(ImportProducts::TYPE), 'nothing runs before the shop starts it');
    }

    public function testRowsUpdateTheirProductsAddNewOnesAndReportWhatTheyRefuse(): void
    {
        $this->shop->content->add(10, 'Lamp');
        $this->shop->products->save(10, new SaveProduct('30', sku: 'LAMP', weight: '2'));
        $this->shop->stock->setLevels(StockItemRef::product(10), new SetStockLevels([new SetStockLevel($this->sofia, 4), new SetStockLevel($this->plovdiv, 9)]));
        $this->shop->content->add(11, 'Hoodie');
        $hoodie = $this->shop->products->save(11, new SaveProduct('40', attributes: [new SaveAttribute('Size', [new SaveOption('S')])]));
        $small = $this->shop->products->addVariation(11, new SaveVariation([$hoodie->attributes[0]->options[0]->id], '41', sku: 'HOOD-S'));

        $view = $this->upload(implode("\n", [
            "id,variation_id,sku,name,price,sale_price,weight,status,categories,stock_{$this->sofia},barcode",
            ',,LAMP,,32.50,29,,draft,Lighting > Desk lamps | Gifts,7,123',
            ",{$small->id},HOOD-S,,45,,,,,2,",
            ',,NEW-1,Bulb,3,,0.1,,,5,456',
            '10,,,,lots,,,,,,',
            ',,,Nameless,,,,,,,',
            '11,,,,,,,,,,abc',
        ]) . "\n");
        $this->imports->start($view->id);
        $done = $this->imports->import($view->id);

        self::assertSame(['done', 6, 1, 2, 3], [$done->status, $done->done, $done->created, $done->updated, $done->failed]);
        self::assertSame([
            'Row 5: price: "lots" is not an amount such as 19.90.',
            'Row 6: A new product needs a name and a price.',
            'Row 7: Saved, but Barcode: a barcode is digits',
        ], $done->errors);

        $lamp = $this->shop->products->product(10);
        self::assertSame(['32.5', '29', null, 'LAMP'], [$lamp->price, $lamp->sale?->price, $lamp->weight, $lamp->sku], 'an empty weight clears it; the SKU found the row');
        self::assertEquals([new ProductPostChange(status: 'draft', categories: ['Lighting > Desk lamps', 'Gifts'])], $this->posts->changes[10]);
        self::assertSame([$this->sofia => 7, $this->plovdiv => 9], $this->shop->stock->levelsOf([StockItemRef::product(10)])['product:10'], 'the stockroom the file has no column of stays');
        self::assertSame(['45', 'HOOD-S'], [$this->shop->products->product(11)->variations[0]->price, $this->shop->products->product(11)->variations[0]->sku]);
        self::assertSame([$this->sofia => 2], $this->shop->stock->levelsOf([StockItemRef::variation($small->id)])["variation:{$small->id}"]);
        $bulb = (int) array_key_first($this->posts->posts);
        self::assertSame(['Bulb', '3', 'NEW-1', '0.1'], [$this->shop->products->product($bulb)->name, $this->shop->products->product($bulb)->price, $this->shop->products->product($bulb)->sku, $this->shop->products->product($bulb)->weight]);
        self::assertSame(['123', '456'], [$this->barcodes[10], $this->barcodes[$bulb]]);
        self::assertNull($this->shop->files->path($this->records->find($view->id)?->fileKey() ?? ''), 'the file is deleted once imported');
    }

    public function testAnImportStartsOnceAndAFileWithoutKnownColumnsIsRefused(): void
    {
        $view = $this->upload("sku,price\nX,1\n");
        $this->imports->start($view->id);
        try {
            $this->imports->start($view->id);
            self::fail('started twice');
        } catch (ExchangeRuleViolation $e) {
            self::assertSame('orillacart_import_started', $e->errorCode);
        }

        $this->expectException(ExchangeRuleViolation::class);
        $this->upload("colour,size\nred,M\n");
    }

    private function upload(string $csv): ImportView
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'import');
        file_put_contents($path, $csv);

        return $this->imports->upload($path, 'products.csv', 7);
    }

    public function testEachImportJobIsReportedAsItsBatchIsDoneAndABadOneDoesNotHoldTheOthers(): void
    {
        $first = $this->upload("sku,price\nX,1\n");
        $second = $this->upload("sku,price\nY,2\n");
        $handler = $this->shop->jobs->get(ImportProducts::TYPE);
        $job = static fn(string $id, mixed $import): QueuedJob => new QueuedJob($id, 'imports', ImportProducts::TYPE, null, ['import' => $import], 1, 1);

        $progress = new RecordedProgress();
        self::assertSame([], $handler->handle([$job('first', $first->id), $job('second', $second->id)], $progress->progress));
        self::assertSame(['first' => 'done', 'second' => 'done'], array_map(static fn(JobOutcome $outcome): string => $outcome->kind, $progress->outcomes), 'neither was started: nothing to go on with');

        $progress = new RecordedProgress();
        $handler->handle([$job('third', $first->id), $job('fourth', 'nope')], $progress->progress);
        self::assertSame(['third' => 'done', 'fourth' => 'fail'], array_map(static fn(JobOutcome $outcome): string => $outcome->kind, $progress->outcomes), 'the first was settled before the second was looked at');
        self::assertSame('The job names no import.', $progress->outcomes['fourth']->error);
    }
}
