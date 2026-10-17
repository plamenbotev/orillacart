<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress;

use OrillaCart\Ordering\Application\OrderLinks;
use OrillaCart\Ordering\Infrastructure\WordPress\Hooks\ServeDownload;
use OrillaCart\Storefront\Application\ShopPagesService;

/**
 * Receipts are on the checkout page, found by the order's key; bought files come through the home address.
 */
final readonly class StorefrontOrderLinks implements OrderLinks
{
    public const string RECEIPT_QUERY = 'orillacart_order';

    public function __construct(private ShopPagesService $pages) {}

    public function receiptUrl(string $key): string
    {
        $checkout = $this->pages->pages()->checkout;
        $page = $checkout === null ? false : get_permalink($checkout);

        return add_query_arg(self::RECEIPT_QUERY, rawurlencode($key), \is_string($page) ? $page : home_url('/'));
    }

    public function downloadUrl(string $token): string
    {
        return add_query_arg(ServeDownload::QUERY, rawurlencode($token), home_url('/'));
    }
}
