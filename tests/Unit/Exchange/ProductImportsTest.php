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
use OrillaCart\Reports\Application\ShopCalendar;
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
        $calendar = new class implements ShopCalendar {
            public function timezone(): \DateTimeZone
            {
                return new \DateTimeZone('Europe/Sofia');
            }
        };
        $rows = new ProductRows($this->shop->products, new ProductLookup($this->shop->productRecords, $this->shop->content), $this->posts, $this->shop->stock, $columns, $calendar);
        $this->imports = new ProductImports($this->records, $columns, new ColumnChoice(new InMemoryExportChoices(), new EnglishColumnNames()), $rows, $this->posts, $this->shop->files, $this->shop->jobs->jobs, $this->shop->transactions, $this->shop->clock, $this->shop->logger);
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
        $bulb = (int) array_key_first($this->posts->authors);
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

    public function testBackordersOwedRoundTripAndOnlyTheFilesStockroomsChange(): void
    {
        $this->shop->content->add(10, 'Lamp');
        $this->shop->products->save(10, new SaveProduct('30', sku: 'LAMP', backorders: 'notify'));
        $this->shop->stock->setLevels(StockItemRef::product(10), new SetStockLevels([new SetStockLevel($this->sofia, 2), new SetStockLevel($this->plovdiv, 0)]));
        $this->shop->stock->take([StockItemRef::product(10)], 5, backorders: true);
        $levels = $this->shop->stock->levelsOf([StockItemRef::product(10)])['product:10'];
        self::assertNotEmpty($levels);
        $owing = (int) array_search(min($levels), $levels, true);
        $other = $owing === $this->sofia ? $this->plovdiv : $this->sofia;
        self::assertSame(-3, $levels[$owing], 'a stockroom owes backorders');

        $done = $this->imported("id,price,stock_{$owing}\n10,31,-3\n10,32,-1\n");

        self::assertSame([2, 1, 1], [$done->done, $done->updated, $done->failed]);
        self::assertStringStartsWith("Row 3: stock_{$owing}: \"-1\" is below zero", $done->errors[0], 'a changed level below zero is refused');
        self::assertSame([$owing => -3, $other => $levels[$other]], $this->shop->stock->levelsOf([StockItemRef::product(10)])['product:10'], 'what is owed stays, as does the stockroom with no column');
        self::assertSame('31', $this->shop->products->product(10)->price, 'the refused row changed nothing');
    }

    public function testANewProductIsADraftUntilItsDetailsAreSavedAndGoesWhenTheyAreRefused(): void
    {
        $this->shop->content->add(10, 'Lamp');
        $this->shop->products->save(10, new SaveProduct('30', sku: 'LAMP'));

        $done = $this->imported("sku,name,price,status,sale_price,sale_from\nNEW-1,Bulb,3,,2,2030-01-01 00:00\nNEW-2,Cable,4,,x,\nlamp,Lamp,5,,,\n");

        $created = array_keys($this->posts->authors);
        self::assertCount(1, $created, 'the bulb: the cable\'s row was refused before anything was made');
        self::assertSame(['draft'], array_values($this->posts->createdAs), 'made as a draft');
        self::assertEquals([new ProductPostChange(status: 'publish')], $this->posts->changes[$created[0]], 'published once its details were saved');
        self::assertSame(7, $this->posts->authors[$created[0]], 'as the user who uploaded the file');
        self::assertSame('2029-12-31T22:00:00+00:00', $this->shop->products->product($created[0])->sale?->from?->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM), 'a date without an offset is the shop\'s');
        self::assertSame([3, 1, 1, 1], [$done->done, $done->created, $done->updated, $done->failed]);
        self::assertSame('5', $this->shop->products->product(10)->price, 'the SKU found the product whatever its letter case, as the database does');
    }

    public function testRowsAreNumberedByTheFilesLinesAndMalformedOnesRefused(): void
    {
        $done = $this->imported("sku,price\n\n\nX,1,extra\n\"Y\nZ\",nope\nW,1,2,3\n");

        self::assertSame([
            'Row 4: The row has 3 cells; the header names 2 columns.',
            'Row 5: A new product needs a name and a price.',
            'Row 7: The row has 4 cells; the header names 2 columns.',
        ], $done->errors, 'blank lines and line breaks in cells count');
    }

    public function testAFileInWindows1252IsReadAndBytesInNeitherAreRefused(): void
    {
        $done = $this->imported(mb_convert_encoding("sku,name,price\nC-1,Café crème,3\n", 'Windows-1252', 'UTF-8'));
        self::assertSame(1, $done->created);
        self::assertSame('Café crème', $this->shop->products->product((int) array_key_first($this->posts->authors))->name);

        $this->expectException(ExchangeRuleViolation::class);
        $this->upload("sku,name,price\nC-2,\x81\xFF,3\n");
    }

    public function testProgressIsWrittenWithEachRowAndAFailedImportEnds(): void
    {
        $view = $this->upload("sku,name,price\nA,One,1\nB,Two,2\nC,Three,3\n");
        $this->startWithoutJob($view->id);
        $handler = $this->shop->jobs->get(ImportProducts::TYPE);
        $job = static fn(int $attempt): QueuedJob => new QueuedJob('j', 'imports', ImportProducts::TYPE, "import:{$view->id}", ['import' => $view->id], 1, $attempt);
        // Each row's progress is written; the end of the batch is not.
        $this->records->breaks = new \RuntimeException('database gone');
        $this->records->breaksAfter = 3;

        $progress = new RecordedProgress();
        $handler->handle([$job(1)], $progress->progress);
        self::assertSame('retry', $progress->outcomes['j']->kind, 'tried again while attempts last');
        self::assertSame([3, 3], [$this->records->progressed, $this->imports->import($view->id)->created], 'each row written with its own progress');
        self::assertCount(1, $this->records->imports, 'no second record');

        $this->records->breaks = new \RuntimeException('database gone');
        $progress = new RecordedProgress();
        $handler->handle([$job(3)], $progress->progress);
        $failed = $this->imports->import($view->id);
        self::assertSame(['fail', 'failed'], [$progress->outcomes['j']->kind, $failed->status], 'the last attempt ends it');
        self::assertSame(['Row 5: the import stopped here: database gone'], $failed->errors, 'after the rows it imported, none of them again');
        self::assertSame([3, 3], [$failed->done, $failed->created]);
        self::assertNull($this->shop->files->path($this->records->find($view->id)?->fileKey() ?? ''), 'its file goes');
    }

    public function testARunningImportWithNoJobLeftCanBeDiscarded(): void
    {
        $view = $this->upload("sku,price\nX,1\n");
        $this->startWithoutJob($view->id);

        $this->imports->discard($view->id);

        self::assertNull($this->records->find($view->id));
    }

    private function startWithoutJob(int $id): void
    {
        $import = $this->records->find($id);
        self::assertNotNull($import);
        $import->start();
        $this->records->save($import);
    }

    private function imported(string $csv): ImportView
    {
        $view = $this->upload($csv);
        $this->imports->start($view->id);

        return $this->imports->import($view->id);
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
