<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress;

use OrillaCart\Storefront\Application\ShopPagesService;

/**
 * Addresses of the shop's pages, for templates. A part of the shop without a page has no address.
 */
final readonly class ShopLinks
{
    public function __construct(private ShopPagesService $pages) {}

    /**
     * @return array{shop: ?string, cart: ?string, checkout: ?string, account: ?string}
     */
    public function pages(): array
    {
        $pages = $this->pages->pages();

        return [
            'shop' => $this->url($pages->shop),
            'cart' => $this->url($pages->cart),
            'checkout' => $this->url($pages->checkout),
            'account' => $this->url($pages->account),
        ];
    }

    /**
     * The page ids, for telling whether a request shows one of them.
     *
     * @return list<int>
     */
    public function pageIds(): array
    {
        $pages = $this->pages->pages();

        return array_values(array_filter([$pages->shop, $pages->cart, $pages->checkout, $pages->account], static fn(?int $id): bool => $id !== null));
    }

    public function isPage(string $part): bool
    {
        $pages = $this->pages->pages();
        $id = match ($part) {
            'shop' => $pages->shop,
            'cart' => $pages->cart,
            'checkout' => $pages->checkout,
            'account' => $pages->account,
            default => null,
        };

        return $id !== null && is_page($id);
    }

    /**
     * Product addresses by id.
     *
     * @param list<int> $ids
     *
     * @return array<int, string>
     */
    public function products(array $ids): array
    {
        $links = [];
        foreach (array_unique($ids) as $id) {
            $link = get_permalink($id);
            if (\is_string($link)) {
                $links[$id] = $link;
            }
        }

        return $links;
    }

    private function url(?int $id): ?string
    {
        $link = $id === null ? false : get_permalink($id);

        return \is_string($link) ? $link : null;
    }
}
