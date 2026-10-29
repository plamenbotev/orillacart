<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress;

use OrillaCart\Ordering\Application\OrderLinks;
use OrillaCart\Ordering\Application\ReceiptLinks;
use OrillaCart\Ordering\Infrastructure\WordPress\Hooks\ServeDownload;
use OrillaCart\Storefront\Application\ShopPagesService;

/**
 * Receipts are on the checkout page, found by a signed link; bought files come through the home address.
 */
final readonly class StorefrontOrderLinks implements OrderLinks
{
    public const string RECEIPT_QUERY = 'orillacart_order';

    public function __construct(
        private ShopPagesService $pages,
        private ReceiptLinks $receipts,
        private ShopLinks $links,
    ) {}

    /** A fresh signed link each time, working for ReceiptLinks::VALID_DAYS. */
    public function receiptUrl(int $orderNumber): string
    {
        // The checkout page in the language the link is written in: an email's, the customer's (design 51).
        $page = $this->links->pageUrl($this->pages->pages()->checkout);

        return add_query_arg(self::RECEIPT_QUERY, $this->receipts->issue($orderNumber), $page ?? home_url('/'));
    }

    public function downloadUrl(string $token): string
    {
        return add_query_arg(ServeDownload::QUERY, rawurlencode($token), home_url('/'));
    }
}
