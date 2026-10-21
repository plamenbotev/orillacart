<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress\Hooks;

use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductBrandTaxonomy;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductCategoryTaxonomy;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductTagTaxonomy;
use OrillaCart\Shared\WordPress\AsHook;
use OrillaCart\Storefront\Infrastructure\WordPress\ShopLinks;
use OrillaCart\Storefront\Infrastructure\WordPress\ShopListing;

/**
 * One address for each listing a search engine should know: a category's or tag's products are at its own page
 * (/product-category/lamps/), so a shop page asked for ?category=lamps or ?tag=gift sends there for good, and
 * its pages are /page/2/ in the path (an older ?product-page=2 sends there too). The Products block counts its
 * own pages, so WordPress's own list of posts on those pages does not 404 them. Listings narrowed by
 * words, filters or sorting, and the shopper's own pages (cart, checkout, account), are not to be indexed.
 */
#[AsHook('template_redirect', method: 'redirect', priority: 5)]
#[AsHook('pre_handle_404', method: 'notFound', filter: true, arguments: 2)]
#[AsHook('wp_robots', method: 'robots', filter: true)]
final readonly class ListingAddresses
{
    private const array TAXONOMIES = [ProductCategoryTaxonomy::NAME, ProductTagTaxonomy::NAME, ProductBrandTaxonomy::NAME];
    /** What narrows or orders a listing: a page of it with any of these is the same products again. */
    private const array NARROWING = ['q', 'sort', 'view', ...ShopListing::FILTER_ARGUMENTS];

    public function __construct(private ShopLinks $links) {}

    public function redirect(): void
    {
        if (!self::isListing()) {
            return;
        }
        $query = [];
        parse_str((string) wp_parse_url(self::requestUri(), \PHP_URL_QUERY), $query);
        foreach (['category' => ProductCategoryTaxonomy::NAME, 'tag' => ProductTagTaxonomy::NAME] as $argument => $taxonomy) {
            $slug = $query[$argument] ?? null;
            $term = \is_string($slug) && $slug !== '' && !is_tax() ? get_term_by('slug', sanitize_title($slug), $taxonomy) : null;
            if ($term instanceof \WP_Term) {
                unset($query[$argument], $query[ShopListing::PAGE_ARGUMENT]);
                $link = get_term_link($term);
                if (\is_string($link)) {
                    self::send($query === [] ? $link : $link . '?' . http_build_query($query));
                }
            }
        }
        $page = $query[ShopListing::PAGE_ARGUMENT] ?? null;
        if (\is_string($page) && ctype_digit($page) && get_option('permalink_structure') !== '') {
            self::send(ShopListing::pageUrl(max(1, (int) $page)));
        }
    }

    /**
     * Page 5 of a category's products is no 404 because WordPress's own list of posts holds no fifth page, nor
     * /shop/2/ because the shop page is not split into pages: the Products block counts its own.
     */
    public function notFound(mixed $handled, \WP_Query $query): mixed
    {
        $paged = $query->is_tax(self::TAXONOMIES) && $query->is_paged() && $query->get_queried_object() instanceof \WP_Term;
        $page = $query->get('page');
        $split = $query->is_singular() && is_numeric($page) && (int) $page > 1 && self::isListing();
        if ($paged || $split) {
            status_header(200);

            return true;
        }

        return $handled;
    }

    /**
     * @param array<string, bool|string> $robots
     *
     * @return array<string, bool|string>
     */
    public function robots(array $robots): array
    {
        foreach (['cart', 'checkout', 'account'] as $part) {
            if ($this->links->isPage($part)) {
                return ['noindex' => true, 'follow' => true] + $robots;
            }
        }
        if (self::isListing() && array_intersect(array_keys($_GET), self::NARROWING) !== []) {
            return ['noindex' => true, 'follow' => true] + $robots;
        }

        return $robots;
    }

    /** A product category's, tag's or brand's page, or a page with the Products block (the shop's own, say). */
    private static function isListing(): bool
    {
        if (is_tax(self::TAXONOMIES)) {
            return true;
        }
        $id = is_singular() || is_front_page() ? get_queried_object_id() : 0;

        return $id > 0 && has_block('orillacart/products', $id);
    }

    private static function send(string $url): never
    {
        wp_safe_redirect($url, 301);
        exit;
    }

    private static function requestUri(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '';

        return \is_string($uri) ? wp_unslash($uri) : '';
    }
}
