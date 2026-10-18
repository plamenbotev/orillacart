<?php

declare(strict_types=1);

namespace OrillaCart\Search\Infrastructure\WordPress;

use OrillaCart\Search\Application\SearchIndexing;
use OrillaCart\Shared\Lifecycle\LifecycleListener;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Queues every product when the plugin is activated or updated, so a shop that had products before the search
 * index (or before the index changed) gets them indexed. After the migrations, which make the tables.
 */
#[AsTaggedItem(priority: -100)]
final readonly class SearchIndexLifecycle implements LifecycleListener
{
    public function __construct(private SearchIndexing $indexing) {}

    public function activate(): void
    {
        $this->indexing->queueEverything();
    }

    public function upgrade(?string $fromVersion): void
    {
        $this->indexing->queueEverything();
    }

    public function deactivate(): void {}

    public function uninstall(): void {}
}
