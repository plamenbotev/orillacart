<?php

declare(strict_types=1);

namespace OrillaCart\Exchange\Infrastructure\WordPress;

/**
 * What an export of any size needs of PHP and WordPress: no time limit and the admin's memory, and WordPress's
 * cache of what it read forgotten between batches, as the exports forget their entities.
 */
final class LongExport
{
    public static function start(): void
    {
        if (\function_exists('set_time_limit')) {
            set_time_limit(0);
        }
        wp_raise_memory_limit('admin');
    }

    public static function between(): void
    {
        if (\function_exists('wp_cache_flush_runtime')) {
            wp_cache_flush_runtime();
        }
    }
}
