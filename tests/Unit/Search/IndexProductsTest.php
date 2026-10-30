<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Search;

use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Catalog\Application\ProductLookup;
use OrillaCart\Inventory\Domain\Event\StockChanged;
use OrillaCart\Search\Application\DocumentBuilder;
use OrillaCart\Search\Application\IndexProducts;
use OrillaCart\Search\Application\SearchIndexing;
use OrillaCart\Search\Application\SearchServer;
use OrillaCart\Search\Domain\EngineSettings;
use OrillaCart\Search\Domain\EngineSettingsRepository;
use OrillaCart\Search\Domain\SearchEngine;
use OrillaCart\Search\Infrastructure\Events\QueueChangedProducts;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use OrillaCart\Tests\Fixtures\Jobs\TestJobs;
use OrillaCart\Tests\Fixtures\Search\FixedProductTexts;
use OrillaCart\Tests\Fixtures\Search\MemorySearchIndex;
use PHPUnit\Framework\TestCase;

final class IndexProductsTest extends TestCase
{
    private TestShop $shop;
    private TestJobs $jobs;
    private MemorySearchIndex $index;
    private FixedProductTexts $texts;
    private EngineSettingsRepository $engine;
    private PreparedServer $server;
    private IndexProducts $handler;

    protected function setUp(): void
    {
        $this->shop = new TestShop();
        foreach ([10, 11, 12] as $id) {
            $this->shop->content->add($id, "Product {$id}");
            $this->shop->products->save($id, new SaveProduct('5', stockMode: 'always_available'));
        }
        $this->index = new MemorySearchIndex();
        $this->texts = new FixedProductTexts();
        $this->engine = new class implements EngineSettingsRepository {
            public EngineSettings $settings;

            public function __construct()
            {
                $this->settings = new EngineSettings(SearchEngine::OpenSearch, 'http://search:9200');
            }

            public function load(): EngineSettings
            {
                return $this->settings;
            }

            public function save(EngineSettings $settings): void
            {
                $this->settings = $settings;
            }
        };
        $this->jobs = new TestJobs($this->shop->clock);
        $this->server = new PreparedServer();
        $this->handler = $handler = new IndexProducts(new DocumentBuilder($this->shop->catalog, $this->texts, $this->shop->priceHistory, $this->shop->contentTranslations), $this->index, $this->jobs->jobs, $this->engine, $this->server);
        $this->jobs->with($handler);
        $this->jobs->jobs->pushMany(IndexProducts::TYPE, array_map(static fn(int $id): array => ['key' => (string) $id, 'payload' => []], [10, 11, 12, 99]));
    }

    public function testABatchIsBuiltAndWrittenTogetherAndOpenSearchAnswersOnceAllAreBuilt(): void
    {
        self::assertFalse($this->engine->load()->ready);

        $run = $this->jobs->runner->work([], 10, 'test');

        self::assertSame(4, $run->done, 'product 99 has no commerce details: it leaves the index');
        self::assertSame([3], $this->index->writes, 'one write for the batch');
        self::assertSame([], $this->jobs->queue->jobs);
        self::assertTrue($this->engine->load()->ready);
    }

    public function testAnIndexBehindThisVersionIsPreparedAgainAndTheBatchWritten(): void
    {
        $this->index->refusals = 1;

        $run = $this->jobs->runner->work([], 10, 'test');

        self::assertSame([4, 1], [$run->done, $this->server->prepared], 'the mapping was brought up to date once');
        self::assertSame([10, 11, 12], array_keys($this->index->documents));
    }

    public function testABatchTheIndexRefusesFailsWithTheReasonWhenTheIndexCannotBePrepared(): void
    {
        $this->index->refusals = \PHP_INT_MAX;
        $this->server->problem = 'OpenSearch answered 401: unauthorized';

        $run = $this->jobs->runner->work([], 10, 'test');

        self::assertSame(0, $run->done);
        self::assertSame([], $this->index->documents);
        $left = array_values($this->jobs->queue->jobs);
        self::assertStringContainsString('could not be prepared: OpenSearch answered 401: unauthorized', (string) $left[0]['error']);
    }

    public function testOneBrokenProductIsRetriedAloneAndTheRestAreBuilt(): void
    {
        $this->texts->broken = 11;

        $run = $this->jobs->runner->work([], 10, 'test');

        self::assertSame([3, 1], [$run->done, $run->retried]);
        self::assertSame([10, 12], array_keys($this->index->documents));
        $left = array_values($this->jobs->queue->jobs);
        self::assertSame(['11', 'Product 11 is broken.'], [$left[0]['key'], $left[0]['error']]);
    }

    public function testOpenSearchWaitsWhileAnotherWorkerStillBuilds(): void
    {
        $this->jobs->queue->claim([], 1, 'other', $this->shop->clock->now(), $this->shop->clock->now()->modify('+5 minutes'));
        $this->jobs->runner->work([], 10, 'test');
        self::assertFalse($this->engine->load()->ready, 'another worker still builds one');
    }

    public function testOpenSearchWaitsWhileAProductCouldNotBeIndexed(): void
    {
        $this->texts->broken = 11;
        for ($attempt = 1; $attempt <= $this->handler->maxAttempts(); ++$attempt) {
            $this->jobs->runner->work([], 10, 'test');
            $this->shop->clock->sleep(3600);
        }
        self::assertCount(1, $this->jobs->jobs->failed(IndexProducts::TYPE));
        self::assertFalse($this->engine->load()->ready, 'shoppers would miss product 11');
    }

    public function testRebuildingRemovesTheDocumentsOfProductsNoLongerInTheCatalog(): void
    {
        $this->jobs->runner->work([], 10, 'test');
        $this->index->documents[77] = $this->index->documents[10];

        new SearchIndexing($this->jobs->jobs, new ProductLookup($this->shop->productRecords, $this->shop->content), $this->handler, $this->index, $this->shop->productTranslations)->queueEverything();

        self::assertSame([10, 11, 12], array_keys($this->index->documents));
    }

    public function testStockBelowZeroIsIndexedAgainSinceTheBackorderLimitMayRunOut(): void
    {
        $this->jobs->runner->work([], 10, 'test');
        $listener = new QueueChangedProducts(new SearchIndexing($this->jobs->jobs, new ProductLookup($this->shop->productRecords, $this->shop->content), $this->handler, $this->index, $this->shop->productTranslations));

        $listener->stockChanged(new StockChanged('product:10', 8, 5));
        self::assertSame([], $this->jobs->queue->jobs, 'still in stock');
        $listener->stockChanged(new StockChanged('product:10', -2, -3));
        self::assertCount(1, $this->jobs->queue->jobs, 'one more owed');
    }
}

/** OpenSearch as a server that is prepared, or answers with what is wrong. */
final class PreparedServer implements SearchServer
{
    public int $prepared = 0;
    public ?string $problem = null;

    public function prepare(EngineSettings $settings): ?string
    {
        ++$this->prepared;

        return $this->problem;
    }
}
