<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Search;

use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Catalog\Application\ProductLookup;
use OrillaCart\Search\Application\DocumentBuilder;
use OrillaCart\Search\Application\IndexProducts;
use OrillaCart\Search\Application\SearchIndexing;
use OrillaCart\Search\Domain\EngineSettings;
use OrillaCart\Search\Domain\EngineSettingsRepository;
use OrillaCart\Search\Domain\SearchEngine;
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
        $this->handler = $handler = new IndexProducts(new DocumentBuilder($this->shop->catalog, $this->texts, $this->shop->priceHistory), $this->index, $this->jobs->jobs, $this->engine);
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

        new SearchIndexing($this->jobs->jobs, new ProductLookup($this->shop->productRecords, $this->shop->content), $this->handler, $this->index)->queueEverything();

        self::assertSame([10, 11, 12], array_keys($this->index->documents));
    }
}
