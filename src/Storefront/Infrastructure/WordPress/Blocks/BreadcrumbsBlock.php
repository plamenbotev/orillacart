<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress\Blocks;

use OrillaCart\Catalog\Application\ProductTranslations;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductBrandTaxonomy;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductCategoryTaxonomy;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductPostType;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductTagTaxonomy;
use OrillaCart\Shared\I18n\ContentLanguages;
use OrillaCart\Shared\WordPress\BlockContext;
use OrillaCart\Shared\WordPress\BlockDefinition;
use OrillaCart\Storefront\Domain\ShopPagesRepository;
use OrillaCart\Storefront\Infrastructure\WordPress\ShopLinks;

/**
 * The way to the page through the shop: Home › Shop › the categories above › the category or the product. A
 * product's way goes through its deepest category. With schema.org BreadcrumbList microdata, which search
 * engines show instead of the address. Nothing on pages that are not the shop's.
 */
final readonly class BreadcrumbsBlock implements BlockDefinition
{
    public function __construct(
        private ShopPagesRepository $pages,
        private ShopLinks $links,
        private ProductTranslations $translations,
        private ContentLanguages $languages,
    ) {}

    public static function name(): string
    {
        return 'orillacart/breadcrumbs';
    }

    public function render(array $attributes, BlockContext $context): string
    {
        $trail = $this->trail($context);
        if ($trail === []) {
            return '';
        }
        if (($attributes['showHome'] ?? true) !== false) {
            array_unshift($trail, [__('Home', 'orillacart'), home_url('/')]);
        }
        $items = [];
        foreach ($trail as $position => [$name, $url]) {
            $last = $position === \count($trail) - 1;
            $items[] = \sprintf(
                '<li itemprop="itemListElement" itemscope itemtype="https://schema.org/ListItem">%s<meta itemprop="position" content="%d"></li>',
                $last
                    ? \sprintf('<span itemprop="name" aria-current="page">%s</span><meta itemprop="item" content="%s">', esc_html($name), esc_url($url))
                    : \sprintf('<a itemprop="item" href="%s"><span itemprop="name">%s</span></a>', esc_url($url), esc_html($name)),
                $position + 1,
            );
        }

        return \sprintf(
            '<nav class="orillacart-breadcrumbs" aria-label="%s"><ol itemscope itemtype="https://schema.org/BreadcrumbList">%s</ol></nav>',
            esc_attr__('Breadcrumbs', 'orillacart'),
            implode('', $items),
        );
    }

    /**
     * The shop page, then the categories down to the page: a product's (its context, or the page's), a
     * category's, tag's or brand's.
     *
     * @return list<array{string, string}> name and address of each
     */
    private function trail(BlockContext $context): array
    {
        $shopId = $this->pages->load()->shop;
        $shopId = $shopId === null ? null : $this->links->pageInLanguage($shopId);
        $shop = $shopId !== null && get_post_status($shopId) === 'publish' ? [[get_the_title($shopId), (string) get_permalink($shopId)]] : [];
        $productId = $context->postId !== null && get_post_type($context->postId) === ProductPostType::NAME
            ? $context->postId
            : (is_singular(ProductPostType::NAME) ? get_queried_object_id() : null);
        if ($productId !== null) {
            return [...$shop, ...$this->categories($this->deepest($productId)), [get_the_title($productId), (string) get_permalink($productId)]];
        }
        $term = get_queried_object();
        if ($term instanceof \WP_Term && \in_array($term->taxonomy, [ProductCategoryTaxonomy::NAME, ProductTagTaxonomy::NAME, ProductBrandTaxonomy::NAME], true)) {
            return [...$shop, ...$this->categories($term->taxonomy === ProductCategoryTaxonomy::NAME ? $term : null), ...($term->taxonomy === ProductCategoryTaxonomy::NAME ? [] : [[$term->name, $this->termLink($term)]])];
        }
        if ($shopId !== null && is_page($shopId)) {
            return $shop;
        }

        return [];
    }

    /**
     * The product's category with the most above it. A translation without categories of its own takes its
     * product's, each in the translation's language where the category has a version in it (design 51).
     */
    private function deepest(int $productId): ?\WP_Term
    {
        $terms = get_the_terms($productId, ProductCategoryTaxonomy::NAME);
        $original = $this->translations->original($productId);
        if (!\is_array($terms) && $original !== $productId) {
            $terms = get_the_terms($original, ProductCategoryTaxonomy::NAME);
            $code = $this->languages->ofPost($productId);
            $terms = \is_array($terms) && $code !== null ? array_map(fn(\WP_Term $term): \WP_Term => $this->inLanguage($term, $code), $terms) : $terms;
        }
        $deepest = null;
        $depth = -1;
        foreach (\is_array($terms) ? $terms : [] as $term) {
            $above = \count(get_ancestors($term->term_id, ProductCategoryTaxonomy::NAME, 'taxonomy'));
            if ($above > $depth) {
                [$deepest, $depth] = [$term, $above];
            }
        }

        return $deepest;
    }

    /** The term's version in the language, when it has one. */
    private function inLanguage(\WP_Term $term, string $code): \WP_Term
    {
        $id = $this->languages->termTranslations($term->term_id, $term->taxonomy)[$code] ?? null;
        $translation = $id === null || $id === $term->term_id ? null : get_term($id, $term->taxonomy);

        return $translation instanceof \WP_Term ? $translation : $term;
    }

    /**
     * The category and those above it, the top one first.
     *
     * @return list<array{string, string}>
     */
    private function categories(?\WP_Term $category): array
    {
        if ($category === null) {
            return [];
        }
        $trail = [];
        foreach ([...array_reverse(get_ancestors($category->term_id, ProductCategoryTaxonomy::NAME, 'taxonomy')), $category->term_id] as $id) {
            $term = get_term($id, ProductCategoryTaxonomy::NAME);
            if ($term instanceof \WP_Term) {
                $trail[] = [$term->name, $this->termLink($term)];
            }
        }

        return $trail;
    }

    private function termLink(\WP_Term $term): string
    {
        $link = get_term_link($term);

        return \is_string($link) ? $link : '';
    }
}
