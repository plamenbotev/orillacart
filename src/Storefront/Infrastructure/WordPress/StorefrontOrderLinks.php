<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress;

use OrillaCart\Ordering\Application\OrderLinks;
use OrillaCart\Ordering\Application\ReceiptLinks;
use OrillaCart\Ordering\Domain\OrderRepository;
use OrillaCart\Ordering\Infrastructure\WordPress\Hooks\ServeDownload;
use OrillaCart\Shared\Lifecycle\Languages;
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
        private OrderRepository $orders,
        private Languages $locales,
    ) {}

    /** A fresh signed link each time, working for ReceiptLinks::VALID_DAYS. */
    public function receiptUrl(int $orderNumber): string
    {
        // The checkout page in the language the customer ordered in (design 51): a gateway's return carries no
        // language and an email is written in the order's, so neither can go by the request's.
        $locale = $this->orders->find($orderNumber)?->locale();
        $page = fn(): ?string => $this->links->pageUrl($this->pages->pages()->checkout);
        $url = $locale === null ? $page() : $this->locales->in($locale, $page);

        return add_query_arg(self::RECEIPT_QUERY, $this->receipts->issue($orderNumber), $url ?? home_url('/'));
    }

    public function downloadUrl(string $token): string
    {
        return add_query_arg(ServeDownload::QUERY, rawurlencode($token), home_url('/'));
    }
}
