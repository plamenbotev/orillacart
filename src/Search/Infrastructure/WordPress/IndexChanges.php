<?php

declare(strict_types=1);

namespace OrillaCart\Search\Infrastructure\WordPress;

/**
 * How often this request changed the search index: what a search found before a change is not reused after it.
 * Kept in WordPress's cache for this request only, as the other per-request reads.
 */
final class IndexChanges
{
    private const string CACHE_GROUP = 'orillacart_request';
    private const string CACHE_KEY = 'search_index_changes';

    public static function count(): int
    {
        wp_cache_add_non_persistent_groups([self::CACHE_GROUP]);
        $count = wp_cache_get(self::CACHE_KEY, self::CACHE_GROUP);

        return \is_int($count) ? $count : 0;
    }

    public static function record(): void
    {
        wp_cache_set(self::CACHE_KEY, self::count() + 1, self::CACHE_GROUP);
    }
}
