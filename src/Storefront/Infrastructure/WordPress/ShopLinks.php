<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress;

use OrillaCart\Catalog\Application\ProductTranslations;
use OrillaCart\Settings\Application\SettingsService;
use OrillaCart\Shared\I18n\ContentLanguages;
use OrillaCart\Storefront\Application\ShopPagesService;

/**
 * Addresses of the shop's pages, for templates. A part of the shop without a page has no address.
 */
final readonly class ShopLinks
{
    public function __construct(
        private ShopPagesService $pages,
        private SettingsService $settings,
        private ProductTranslations $translations,
        private ContentLanguages $languages,
    ) {}

    /**
     * The pages' addresses. A shop that only shows its catalog has no cart or checkout to send shoppers to.
     *
     * @return array{shop: ?string, cart: ?string, checkout: ?string, account: ?string}
     */
    public function pages(): array
    {
        $pages = $this->pages->pages();
        $sells = !$this->settings->current()->catalogOnly;

        return [
            'shop' => $this->url($pages->shop),
            'cart' => $sells ? $this->url($pages->cart) : null,
            'checkout' => $sells ? $this->url($pages->checkout) : null,
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
            // The address of the product's text in the shopper's language (design 51).
            $link = get_permalink($this->translations->shown($id));
            if (\is_string($link)) {
                $links[$id] = $link;
            }
        }

        return $links;
    }

    /** A page's address in the request's language, or the language an email is written in; null for none. */
    public function pageUrl(?int $id): ?string
    {
        return $this->url($id);
    }

    private function url(?int $id): ?string
    {
        $link = $id === null ? false : get_permalink($this->inLanguage($id));

        return \is_string($link) ? $link : null;
    }

    /** A shop page's translation in the request's language, when it has a published one. */
    private function inLanguage(int $pageId): int
    {
        $language = $this->languages->current();
        $translation = $language === null ? null : ($this->languages->postTranslations($pageId)[$language->code] ?? null);

        return $translation !== null && get_post_status($translation) === 'publish' ? $translation : $pageId;
    }
}
