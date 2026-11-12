<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Infrastructure\WordPress;

use OrillaCart\Packages\Application\RolledBackCaches;

/**
 * WordPress's object cache around an extension's test (design 69). While it runs, nothing new is added (what a
 * query reads is not kept); afterwards the groups a test can write to are let go: this request's copies, and with a
 * persistent cache (Redis, Memcached) the stored ones, group by group where the cache can, the whole cache where it
 * cannot. Tests are run by hand, rarely: the cost is the next requests reading from the database again.
 */
final readonly class WordPressRolledBackCaches implements RolledBackCaches
{
    /** What a test's products, customers, terms, options and the shop's own lists keep. */
    public const array GROUPS = ['posts', 'post_meta', 'post-queries', 'terms', 'term_meta', 'term_relationships', 'term-queries', 'options', 'transient', 'counts', 'users', 'user_meta', 'userlogins', 'useremail', 'userslugs', 'user-queries', 'orillacart_listing', 'orillacart_search', 'orillacart_request'];

    public function around(callable $work): mixed
    {
        $suspended = wp_suspend_cache_addition();
        wp_suspend_cache_addition(true);
        try {
            return $work();
        } finally {
            wp_suspend_cache_addition($suspended);
            self::letGo();
        }
    }

    private static function letGo(): void
    {
        if (wp_using_ext_object_cache()) {
            if (\function_exists('wp_cache_supports') && wp_cache_supports('flush_group')) {
                foreach (self::GROUPS as $group) {
                    wp_cache_flush_group($group);
                }
            } else {
                wp_cache_flush();
            }
        }
        if (\function_exists('wp_cache_supports') && wp_cache_supports('flush_runtime')) {
            wp_cache_flush_runtime();
        } elseif (!wp_using_ext_object_cache()) {
            wp_cache_flush();
        }
    }
}
