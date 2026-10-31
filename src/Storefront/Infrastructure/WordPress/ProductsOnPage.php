<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress;

use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductBrandTaxonomy;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductCategoryTaxonomy;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductTagTaxonomy;
use OrillaCart\Storefront\Domain\ShopPagesRepository;

/**
 * The Products block of the page being shown, for the blocks placed apart from it (filters, sort, count, pages,
 * active filters): the first one in the page's own blocks, also inside columns and groups, else in the block
 * theme's template; on a category's, tag's or brand's page, the shop page's (which ProductArchives lays out
 * there). Blocks render in order, so one above the grid cannot wait for it to say what it lists.
 */
final readonly class ProductsOnPage
{
    public const string PRODUCTS = 'orillacart/products';
    /** The blocks that count the filters' values: a search for the page counts them for all of them. */
    private const array COUNTING = ['orillacart/product-filters', 'orillacart/active-filters'];
    private const string CACHE_GROUP = 'orillacart_request';

    public function __construct(
        private ShopPagesRepository $pages,
        private ShopLinks $links,
    ) {}

    /** What the page's Products block lists; null when the page has none. */
    public function scope(): ?ListingScope
    {
        foreach ($this->sources() as $content) {
            $found = self::find($content);
            if ($found !== null) {
                return ListingScope::of($found['attributes'], $found['facets']);
            }
        }

        // A category's page without the shop page's blocks shows the Products block on its own (ProductArchives).
        return is_tax([ProductCategoryTaxonomy::NAME, ProductTagTaxonomy::NAME, ProductBrandTaxonomy::NAME]) ? ListingScope::of([]) : null;
    }

    /**
     * Where the page's blocks are, in the order they are looked for.
     *
     * @return iterable<string>
     */
    private function sources(): iterable
    {
        $page = is_singular() ? get_queried_object() : null;
        if ($page instanceof \WP_Post) {
            yield $page->post_content;
        }
        global $_wp_current_template_content;
        if (\is_string($_wp_current_template_content)) {
            yield $_wp_current_template_content;
        }
        if (is_tax([ProductCategoryTaxonomy::NAME, ProductTagTaxonomy::NAME, ProductBrandTaxonomy::NAME])) {
            $id = $this->pages->load()->shop;
            $shop = $id === null ? null : get_post($this->links->pageInLanguage($id));
            if ($shop instanceof \WP_Post && $shop->post_status === 'publish') {
                yield $shop->post_content;
            }

            return;
        }
        // The editor's preview renders the block for the post being edited.
        $post = get_post();
        if ($post instanceof \WP_Post && $post !== $page) {
            yield $post->post_content;
        }
    }

    /**
     * The first Products block's attributes in the blocks, and whether a block among them counts the filters.
     *
     * @return array{attributes: array<mixed>, facets: bool}|null
     */
    private static function find(string $content): ?array
    {
        if (!has_block(self::PRODUCTS, $content)) {
            return null;
        }
        // The page's blocks are read once a request, however many blocks ask.
        wp_cache_add_non_persistent_groups([self::CACHE_GROUP]);
        $key = 'products_on_page:' . md5($content);
        $known = wp_cache_get($key, self::CACHE_GROUP);
        if (\is_array($known) && \is_array($known['attributes'] ?? null) && \is_bool($known['facets'] ?? null)) {
            return ['attributes' => $known['attributes'], 'facets' => $known['facets']];
        }
        $attributes = null;
        $facets = false;
        $walk = static function (array $blocks) use (&$walk, &$attributes, &$facets): void {
            foreach ($blocks as $block) {
                if (!\is_array($block)) {
                    continue;
                }
                $name = $block['blockName'] ?? null;
                if ($name === self::PRODUCTS && $attributes === null) {
                    $attributes = \is_array($block['attrs'] ?? null) ? $block['attrs'] : [];
                }
                $facets = $facets || \in_array($name, self::COUNTING, true);
                if (\is_array($block['innerBlocks'] ?? null)) {
                    $walk($block['innerBlocks']);
                }
            }
        };
        $walk(parse_blocks($content));
        if ($attributes === null) {
            return null;
        }
        $found = ['attributes' => $attributes, 'facets' => $facets];
        wp_cache_set($key, $found, self::CACHE_GROUP);

        return $found;
    }
}
