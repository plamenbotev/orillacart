<?php

declare(strict_types=1);

namespace OrillaCart\Search\Infrastructure\WordPress\Admin;

use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductPostType;
use OrillaCart\Search\Application\SearchEngines;
use OrillaCart\Search\Application\SearchIndex;
use OrillaCart\Search\Application\SearchIndexing;
use OrillaCart\Shared\WordPress\HealthCheck;
use OrillaCart\Shared\WordPress\HealthStatus;

/**
 * Whether the search index holds the published products: the shop page lists only what it holds. Products
 * waiting for their turn count as on their way; builds that failed too often, or an index short of products
 * with nothing queued, want a rebuild (`wp orillacart index rebuild`, or `wp orillacart doctor --fix`). An
 * OpenSearch index that cannot be given this version's fields is told of first: nothing can be indexed into it.
 */
final readonly class SearchHealth implements HealthCheck
{
    public function __construct(
        private SearchIndex $index,
        private SearchIndexing $indexing,
        private SearchEngines $engines,
    ) {}

    public static function id(): string
    {
        return 'search';
    }

    public static function label(): string
    {
        return __('The shop’s search index', 'orillacart');
    }

    public function check(): HealthStatus
    {
        // OpenSearch's index is asked for this version's fields, which is also what gives it them (PLAN 245).
        $unprepared = $this->engines->prepareIndex();
        if ($unprepared !== null) {
            return HealthStatus::critical(
                __('The search index is not ready for this version', 'orillacart'),
                /* translators: %s: what OpenSearch answered */
                \sprintf(__('OpenSearch’s index could not be prepared: %s', 'orillacart'), $unprepared) . ' ' . __('Products cannot be indexed until it is reached. Check the search engine’s settings, then rebuild the index.', 'orillacart'),
                admin_url('admin.php?page=orillacart-settings'),
                __('Search engine', 'orillacart'),
            );
        }
        $status = $this->indexing->status();
        if ($status->parked !== []) {
            return HealthStatus::critical(
                __('Some products are missing from the shop page', 'orillacart'),
                /* translators: %d: how many products */
                \sprintf(_n('%d product could not be added to the search index.', '%d products could not be added to the search index.', \count($status->parked), 'orillacart'), \count($status->parked)) . ' ' . __('Run "wp orillacart doctor --fix", or see the Background jobs screen.', 'orillacart'),
                admin_url('admin.php?page=orillacart-jobs'),
                __('Background jobs', 'orillacart'),
            );
        }
        $indexed = \count($this->index->productIds());
        $published = self::published();
        if ($status->due === 0 && $indexed < $published) {
            return HealthStatus::recommended(
                __('The search index is short of products', 'orillacart'),
                /* translators: 1: products in the index, 2: published products */
                \sprintf(__('The index holds %1$d products of %2$d published, and nothing is on its way. Run "wp orillacart doctor --fix" to rebuild it.', 'orillacart'), $indexed, $published),
            );
        }

        return HealthStatus::good(__('The search index holds the published products', 'orillacart'));
    }

    public function info(): array
    {
        $status = $this->indexing->status();

        return [
            'indexed' => ['label' => __('Products in the index', 'orillacart'), 'value' => (string) \count($this->index->productIds())],
            'published' => ['label' => __('Published products', 'orillacart'), 'value' => (string) self::published()],
            'due' => ['label' => __('Products waiting to be indexed', 'orillacart'), 'value' => (string) $status->due],
        ];
    }

    private static function published(): int
    {
        $counts = wp_count_posts(ProductPostType::NAME);

        return isset($counts->publish) && is_numeric($counts->publish) ? (int) $counts->publish : 0;
    }
}
