<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Infrastructure\WordPress;

use OrillaCart\Ordering\Application\OrderLinks;

/**
 * Receipts are reached through the site's home address with the order key; the storefront shows the order
 * there. The filter orillacart_order_received_url can send customers elsewhere.
 */
final readonly class HomeOrderLinks implements OrderLinks
{
    public const string QUERY = 'orillacart_order';

    public function receiptUrl(string $key): string
    {
        $url = apply_filters('orillacart_order_received_url', add_query_arg(self::QUERY, rawurlencode($key), home_url('/')), $key);

        return \is_string($url) ? $url : home_url('/');
    }
}
