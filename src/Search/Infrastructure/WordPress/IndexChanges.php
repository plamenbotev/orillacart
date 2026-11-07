<?php

declare(strict_types=1);

namespace OrillaCart\Search\Infrastructure\WordPress;

/**
 * The search index's generation: counts up at each change to it, so what a search found in one generation is not
 * reused in the next. Kept in WordPress's object cache: for this request only on a site without a persistent one,
 * shared by the requests on a site with one (Redis, Memcached), where the listings' results are shared too
 * (design 55).
 */
final class IndexChanges
{
    private const string CACHE_GROUP = 'orillacart_search';
    private const string CACHE_KEY = 'index_generation';

    public static function generation(): int
    {
        $generation = wp_cache_get(self::CACHE_KEY, self::CACHE_GROUP);

        return \is_int($generation) ? $generation : 0;
    }

    public static function record(): void
    {
        // add() leaves a generation that is there alone; incr() then counts it up atomically where the cache is shared.
        wp_cache_add(self::CACHE_KEY, 0, self::CACHE_GROUP);
        if (wp_cache_incr(self::CACHE_KEY, 1, self::CACHE_GROUP) === false) {
            wp_cache_set(self::CACHE_KEY, self::generation() + 1, self::CACHE_GROUP);
        }
    }
}
