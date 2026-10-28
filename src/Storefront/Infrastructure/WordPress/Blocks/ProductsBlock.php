<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress\Blocks;

use OrillaCart\Catalog\Application\Dto\ProductOffer;
use OrillaCart\Catalog\Application\ProductTranslations;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductBrandTaxonomy;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductCategoryTaxonomy;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductPostType;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductTagTaxonomy;
use OrillaCart\Catalog\Infrastructure\WordPress\Hooks\CategoryLayout;
use OrillaCart\Catalog\Infrastructure\WordPress\ProductCategories;
use OrillaCart\Shared\View\TemplateRenderer;
use OrillaCart\Shared\WordPress\BlockContext;
use OrillaCart\Shared\WordPress\BlockDefinition;
use OrillaCart\Storefront\Infrastructure\WordPress\ProductCards;
use OrillaCart\Storefront\Infrastructure\WordPress\ProductParts;
use OrillaCart\Storefront\Infrastructure\WordPress\ShopLinks;
use OrillaCart\Storefront\Infrastructure\WordPress\ShopListing;

/**
 * The shop: published products in a grid, which visitors narrow by category, tag, words, brand, the shop's
 * filters, price and stock, and sort; on a category's, tag's or brand's own page, that one's products. The
 * products and the counts come from the search index; their cards show the catalog's offers as they are now.
 * The query string carries the choices (ShopListing), so pages can be linked and work without the script. The
 * filters show here, or (filters off) in a Product filters block elsewhere.
 */
final readonly class ProductsBlock implements BlockDefinition
{
    public const array SORTS = ShopListing::SORTS;

    public function __construct(
        private ShopListing $listing,
        private ProductCategories $categories,
        private ShopLinks $links,
        private TemplateRenderer $templates,
        private ProductCards $cards,
        private ProductParts $parts,
        private ProductTranslations $translations,
    ) {}

    public static function name(): string
    {
        return 'orillacart/products';
    }

    public function render(array $attributes, BlockContext $context): string
    {
        $perPage = max(1, min(60, \is_int($attributes['perPage'] ?? null) ? $attributes['perPage'] : 12));
        $columns = max(1, min(6, \is_int($attributes['columns'] ?? null) ? $attributes['columns'] : 3));
        $fixedCategory = \is_string($attributes['category'] ?? null) ? sanitize_title($attributes['category']) : '';
        // A block that always shows one category has no links to the others.
        $ownCategory = $fixedCategory !== '';
        $fixedTag = \is_string($attributes['tag'] ?? null) ? sanitize_title($attributes['tag']) : '';
        $fixedBrand = null;
        // On a category's, tag's or brand's own page, a block that names none shows that one's products.
        $term = get_queried_object();
        if ($fixedCategory === '' && $fixedTag === '' && $term instanceof \WP_Term && is_tax()) {
            match ($term->taxonomy) {
                ProductCategoryTaxonomy::NAME => $fixedCategory = $term->slug,
                ProductTagTaxonomy::NAME => $fixedTag = $term->slug,
                ProductBrandTaxonomy::NAME => $fixedBrand = $term->term_id,
                default => null,
            };
        }
        $showFilters = ($attributes['filters'] ?? true) !== false;

        $choices = $this->listing->choices($fixedCategory, \is_string($attributes['sort'] ?? null) ? $attributes['sort'] : 'order', $fixedTag, $fixedBrand);
        $result = $this->listing->search(
            $choices,
            ($choices['page'] - 1) * $perPage,
            $perPage,
            $showFilters,
            ($attributes['featured'] ?? false) === true,
            ($attributes['onSale'] ?? false) === true,
        );
        $panel = $this->listing->panel($choices, $result);
        // The shopper's way to see them, else the category's (or one above it), else the block's.
        $categoryLayout = (int) $choices['category_id'] > 0 ? CategoryLayout::of((int) $choices['category_id']) : null;
        $layout = $choices['view'] !== '' ? $choices['view'] : ($categoryLayout ?? (($attributes['layout'] ?? 'grid') === 'list' ? 'list' : 'grid'));
        $products = $this->cards->cards($result->ids, $layout === 'list');
        $card = $layout === 'grid' ? self::card($context) : null;

        $base = remove_query_arg(['category', 'tag', 'q', 'sort', ...ShopListing::FILTER_ARGUMENTS], ShopListing::pageUrl(1));
        $pages = (int) ceil($result->total / $perPage);
        $pageUrls = [];
        for ($number = 1; $number <= $pages; ++$number) {
            $pageUrls[$number] = ShopListing::pageUrl($number);
        }
        // A category's link is its own page, keeping only how the shopper sees the products.
        $keep = static fn(string $url): string => $choices['view'] === '' ? $url : add_query_arg('view', $choices['view'], $url);

        // The category chosen, the one above it, and those below it or beside it: the second row of links.
        $chosen = !$ownCategory && (int) $choices['category_id'] > 0 ? (int) $choices['category_id'] : null;
        $path = $chosen === null ? [] : [$chosen, ...array_map(intval(...), get_ancestors($chosen, ProductCategoryTaxonomy::NAME, 'taxonomy'))];
        $below = $chosen === null ? [] : $this->categories->childrenOf($chosen);
        // A category's own page shows those below it as tiles (ProductArchives); the row then repeats nothing.
        if ($below !== [] && is_tax(ProductCategoryTaxonomy::NAME)) {
            $below = [];
        } elseif ($chosen !== null && $below === [] && \count($path) > 1) {
            $below = $this->categories->childrenOf($path[1]);
        }

        $on = static fn(string $name): bool => ($attributes[$name] ?? true) !== false;

        return $this->templates->render('storefront/products.html.twig', [
            'products' => $products,
            // The shop's own card design: each product's blocks, rendered with the product as theirs.
            'card_html' => $card === null ? null : $this->cardHtml($card, $products),
            'show' => [
                'categories' => $on('showCategories'),
                'search' => $on('showSearch'),
                'layouts' => $on('showLayouts'),
                'count' => $on('showCount'),
                'pagination' => $on('showPagination'),
            ],
            'first' => ($choices['page'] - 1) * $perPage + 1,
            'last' => ($choices['page'] - 1) * $perPage + \count($products),
            'total' => $result->total,
            'categories' => $ownCategory ? [] : array_map(static fn(array $term): array => $term + [
                'url' => $keep($term['url']),
                // The one chosen is this page; the one above it holds it.
                'current' => $term['id'] === $chosen ? 'page' : (\in_array($term['id'], $path, true) ? 'true' : ''),
            ], $this->categories->ordered()),
            'subcategories' => array_map(static fn(array $term): array => [
                'name' => $term['name'],
                'url' => $keep($term['url']),
                'current' => $term['id'] === $chosen,
            ], $below),
            'current_category' => $fixedCategory !== '' ? '' : $choices['category'],
            'all_url' => $keep($this->links->pages()['shop'] ?? $base),
            // The whole shop is this page when no category, tag or brand narrows it.
            'all_current' => $chosen === null && $fixedTag === '' && $fixedBrand === null && $choices['tag'] === '',
            'base_url' => $base,
            'search' => $choices['search'],
            'sort' => $choices['sort'],
            'sorts' => ($choices['search'] === '' ? [] : ['relevance' => __('Best match', 'orillacart')]) + [
                'order' => __('Recommended', 'orillacart'),
                'newest' => __('Newest', 'orillacart'),
                'name' => __('Name', 'orillacart'),
                'price_asc' => __('Price: low to high', 'orillacart'),
                'price_desc' => __('Price: high to low', 'orillacart'),
            ],
            'page' => $choices['page'],
            'pages' => $pages,
            'page_urls' => $pageUrls,
            'columns' => $columns,
            'layout' => $layout,
            'layout_urls' => [
                'grid' => add_query_arg('view', 'grid', ShopListing::pageUrl(1)),
                'list' => add_query_arg('view', 'list', ShopListing::pageUrl(1)),
            ],
            'view' => $choices['view'],
            'cart_url' => $this->links->pages()['cart'],
            'show_filters' => $showFilters,
            'panel' => $panel,
            // Without its own panel, the form keeps what a Product filters block chose.
            'hidden' => $showFilters ? [] : $this->listing->hidden($choices, true),
            'filtered' => $panel['filtered'],
        ]);
    }

    /**
     * The Product card block inside, if the shop designed its card.
     *
     * @return array{blockName: string, attrs: array<mixed>, innerBlocks: array<mixed>, innerHTML: string, innerContent: array<mixed>}|null
     */
    private static function card(BlockContext $context): ?array
    {
        foreach ($context->innerBlocks as $block) {
            if (($block['blockName'] ?? null) === ProductCardBlock::NAME) {
                return [
                    'blockName' => ProductCardBlock::NAME,
                    'attrs' => \is_array($block['attrs'] ?? null) ? $block['attrs'] : [],
                    'innerBlocks' => \is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : [],
                    'innerHTML' => \is_string($block['innerHTML'] ?? null) ? $block['innerHTML'] : '',
                    'innerContent' => \is_array($block['innerContent'] ?? null) ? $block['innerContent'] : [],
                ];
            }
        }

        return null;
    }

    /**
     * Each product's card from the card's blocks, with the product as their context.
     *
     * @param array{blockName: string, attrs: array<mixed>, innerBlocks: array<mixed>, innerHTML: string, innerContent: array<mixed>} $card
     * @param list<array<string, mixed>>                                                                                             $products
     *
     * @return list<array{id: int, html: string}>
     */
    private function cardHtml(array $card, array $products): array
    {
        $ids = array_values(array_map(static fn(array $product): int => $product['offer'] instanceof ProductOffer ? $product['offer']->id : 0, $products));
        $this->parts->warm($ids);
        $html = [];
        // Core blocks such as Post title read the global post, as WordPress's Query Loop sets it for each.
        global $post;
        foreach ($ids as $id) {
            // The card shows the product's translation in the shopper's language; its blocks sell the original.
            $shown = $this->translations->shown($id);
            $post = get_post($shown);
            if (!$post instanceof \WP_Post) {
                continue;
            }
            setup_postdata($post);
            $html[] = ['id' => $id, 'html' => new \WP_Block($card, ['postId' => $shown, 'postType' => ProductPostType::NAME])->render()];
        }
        wp_reset_postdata();

        return $html;
    }
}
