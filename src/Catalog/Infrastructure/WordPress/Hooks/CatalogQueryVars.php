<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Infrastructure\WordPress\Hooks;

use OrillaCart\Shared\WordPress\AsHook;

/**
 * Lets product queries (WP_Query) sort and filter by what the Catalog tables hold:
 *
 *     new WP_Query(['post_type' => 'orillacart_product', 'orillacart_sort' => 'price_asc', 'orillacart_on_sale' => true]);
 *
 * orillacart_sort: price_asc | price_desc, by today's price (a running sale counts; a product with variations by its
 * lowest). orillacart_featured and orillacart_on_sale: only featured products, or those with a sale running.
 * Products whose commerce details were never saved are left out of such queries.
 */
#[AsHook('posts_clauses', filter: true, arguments: 2)]
final readonly class CatalogQueryVars
{
    /**
     * @param array<string, string> $clauses
     *
     * @return array<string, string>
     */
    public function __invoke(array $clauses, \WP_Query $query): array
    {
        $sort = $query->get('orillacart_sort');
        $featured = $query->get('orillacart_featured') === true;
        $onSale = $query->get('orillacart_on_sale') === true;
        if (!\in_array($sort, ['price_asc', 'price_desc'], true) && !$featured && !$onSale) {
            return $clauses;
        }

        global $wpdb;
        if (!$wpdb instanceof \wpdb) {
            return $clauses;
        }
        $products = $wpdb->prefix . 'orillacart_products';
        $variations = $wpdb->prefix . 'orillacart_variations';
        $running = static fn(string $table): string => "({$table}.sale_price IS NOT NULL AND ({$table}.sale_from IS NULL OR {$table}.sale_from <= UTC_TIMESTAMP()) AND ({$table}.sale_until IS NULL OR {$table}.sale_until > UTC_TIMESTAMP()))";
        $price = static fn(string $table): string => "CASE WHEN {$running($table)} THEN {$table}.sale_price ELSE {$table}.price END";

        $clauses['join'] .= " INNER JOIN {$products} AS orillacart_p ON orillacart_p.id = {$wpdb->posts}.ID";
        if ($featured) {
            $clauses['where'] .= ' AND orillacart_p.featured = 1';
        }
        if ($onSale) {
            $clauses['where'] .= " AND ({$running('orillacart_p')} OR EXISTS (SELECT 1 FROM {$variations} AS orillacart_v WHERE orillacart_v.product_id = orillacart_p.id AND {$running('orillacart_v')}))";
        }
        if (\is_string($sort) && $sort !== '' && str_starts_with($sort, 'price_')) {
            $current = "COALESCE((SELECT MIN({$price('orillacart_v')}) FROM {$variations} AS orillacart_v WHERE orillacart_v.product_id = orillacart_p.id), {$price('orillacart_p')})";
            $direction = $sort === 'price_desc' ? 'DESC' : 'ASC';
            $clauses['orderby'] = "{$current} {$direction}, {$wpdb->posts}.post_title ASC";
        }

        return $clauses;
    }
}
