<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Search;

use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Search\Application\DocumentBuilder;
use OrillaCart\Search\Application\IndexProducts;
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
        $handler = new IndexProducts(new DocumentBuilder($this->shop->catalog, $this->texts, $this->shop->priceHistory), $this->index, $this->jobs->jobs, $this->engine);
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
}
