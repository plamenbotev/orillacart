<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Search;

use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Search\Application\DocumentBuilder;
use OrillaCart\Search\Application\IndexWorker;
use OrillaCart\Search\Domain\EngineSettings;
use OrillaCart\Search\Domain\EngineSettingsRepository;
use OrillaCart\Shared\Persistence\LoadedEntities;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use OrillaCart\Tests\Fixtures\Http\MemoryLogger;
use OrillaCart\Tests\Fixtures\Search\FixedProductTexts;
use OrillaCart\Tests\Fixtures\Search\MemoryIndexQueue;
use OrillaCart\Tests\Fixtures\Search\MemorySearchIndex;
use PHPUnit\Framework\TestCase;

final class IndexWorkerTest extends TestCase
{
    private TestShop $shop;
    private MemoryIndexQueue $queue;
    private MemorySearchIndex $index;
    private FixedProductTexts $texts;
    private MemoryLogger $logger;
    private IndexWorker $worker;

    protected function setUp(): void
    {
        $this->shop = new TestShop();
        foreach ([10, 11, 12] as $id) {
            $this->shop->content->add($id, "Product {$id}");
            $this->shop->products->save($id, new SaveProduct('5', stockMode: 'always_available'));
        }
        $this->queue = new MemoryIndexQueue();
        $this->index = new MemorySearchIndex();
        $this->texts = new FixedProductTexts();
        $this->logger = new MemoryLogger();
        $this->worker = new IndexWorker(
            $this->queue,
            new DocumentBuilder($this->shop->catalog, $this->texts),
            $this->index,
            new class implements LoadedEntities {
                public function forget(): void {}
            },
            $this->shop->clock,
            $this->logger,
            new class implements EngineSettingsRepository {
                public function load(): EngineSettings
                {
                    return new EngineSettings();
                }

                public function save(EngineSettings $settings): void {}
            },
        );
        $this->queue->push([10, 11, 12, 99], $this->shop->clock->now());
    }

    public function testABatchIsBuiltAndWrittenTogether(): void
    {
        $run = $this->worker->work(10);

        self::assertSame([3, 1, 0], [$run->built, $run->removed, $run->failed], 'product 99 has no commerce details');
        self::assertSame([3], $this->index->writes, 'one write for the batch');
        self::assertSame([], $this->queue->jobs);
    }

    public function testOneBrokenProductFailsAloneAndTheRestAreBuilt(): void
    {
        $this->texts->broken = 11;

        $run = $this->worker->work(10);

        self::assertSame([2, 1, 1], [$run->built, $run->removed, $run->failed]);
        self::assertSame([10, 12], array_keys($this->index->documents));
        self::assertSame([11], array_keys($this->queue->jobs), 'it waits to be tried again');
        self::assertSame('Product 11 is broken.', $this->queue->jobs[11]['error']);
        self::assertSame('error', $this->logger->records[0]['level'] ?? null);
    }
}
