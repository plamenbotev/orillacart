<?php

declare(strict_types=1);

namespace OrillaCart\Search\Infrastructure\WordPress;

use OrillaCart\Search\Application\SearchEngines;
use OrillaCart\Search\Application\SearchIndexing;
use OrillaCart\Shared\Lifecycle\LifecycleListener;
use OrillaCart\Shared\Lifecycle\RemovesShopData;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Queues every product when the plugin is activated or updated, so a shop that had products before the search
 * index (or before the index changed) gets them indexed, OpenSearch's index given its new fields first. After the
 * migrations, which make the tables.
 */
#[AsTaggedItem(priority: -100)]
final readonly class SearchIndexLifecycle implements LifecycleListener, RemovesShopData
{
    public function __construct(
        private SearchIndexing $indexing,
        private SearchEngines $engines,
    ) {}

    public function activate(): void
    {
        $this->indexing->queueEverything();
    }

    public function upgrade(?string $fromVersion): void
    {
        // OpenSearch's index learns the fields added since; a server not reached fails the jobs, which try again.
        $this->engines->prepareIndex();
        $this->indexing->queueEverything();
    }

    public function deactivate(): void {}

    public function uninstall(): void {}
}
