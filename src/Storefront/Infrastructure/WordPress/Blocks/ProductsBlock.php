<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress\Blocks;

use OrillaCart\Catalog\Application\ShopCatalog;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductCategoryTaxonomy;
use OrillaCart\Catalog\Infrastructure\WordPress\ProductCategories;
use OrillaCart\Search\Application\Dto\SearchQuery;
use OrillaCart\Search\Application\Dto\SearchSort;
use OrillaCart\Search\Application\ShopSearch;
use OrillaCart\Shared\View\TemplateRenderer;
use OrillaCart\Shared\WordPress\BlockDefinition;
use OrillaCart\Storefront\Infrastructure\WordPress\ShopLinks;

/**
 * The shop: published products in a grid, which visitors narrow by category and search, and sort. The products
 * come from the search index; their cards show the catalog's offers as they are now.
 * The query string carries the choices (q, category, sort, product-page), so pages can be linked.
 */
final readonly class ProductsBlock implements BlockDefinition
{
    public const array SORTS = ['order', 'newest', 'name', 'price_asc', 'price_desc'];

    public function __construct(
        private ShopCatalog $catalog,
        private ShopSearch $search,
        private ProductCategories $categories,
        private ShopLinks $links,
        private TemplateRenderer $templates,
    ) {}

    public static function name(): string
    {
        return 'orillacart/products';
    }

    public static function metadata(): array
    {
        return [
            'title' => __('Products', 'orillacart'),
            'description' => __('The shop: products with their prices, by category, with search.', 'orillacart'),
            'icon' => 'products',
            'keywords' => [__('shop', 'orillacart'), __('catalog', 'orillacart')],
            'attributes' => [
                'perPage' => ['type' => 'integer', 'default' => 12],
                'columns' => ['type' => 'integer', 'default' => 3],
                'category' => ['type' => 'string', 'default' => ''],
                'sort' => ['type' => 'string', 'default' => 'order'],
                'featured' => ['type' => 'boolean', 'default' => false],
                'onSale' => ['type' => 'boolean', 'default' => false],
            ],
        ];
    }

    public function render(array $attributes): string
    {
        $perPage = max(1, min(60, \is_int($attributes['perPage'] ?? null) ? $attributes['perPage'] : 12));
        $columns = max(1, min(6, \is_int($attributes['columns'] ?? null) ? $attributes['columns'] : 3));
        $fixedCategory = \is_string($attributes['category'] ?? null) ? sanitize_title($attributes['category']) : '';

        $category = $fixedCategory !== '' ? $fixedCategory : self::query('category');
        $search = self::query('q');
        $defaultSort = \is_string($attributes['sort'] ?? null) && \in_array($attributes['sort'], self::SORTS, true) ? $attributes['sort'] : 'order';
        $sorts = $search === '' ? self::SORTS : ['relevance', ...self::SORTS];
        // Searching sorts by best match unless the shopper chose otherwise.
        $sort = \in_array(self::query('sort'), $sorts, true) ? self::query('sort') : ($search === '' ? $defaultSort : 'relevance');
        $page = max(1, ctype_digit(self::query('product-page')) ? (int) self::query('product-page') : 1);

        $categoryId = null;
        if ($category !== '') {
            $term = get_term_by('slug', $category, ProductCategoryTaxonomy::NAME);
            $categoryId = $term instanceof \WP_Term ? $term->term_id : 0;
        }
        $result = $this->search->search(new SearchQuery(
            text: $search === '' ? null : $search,
            // An unknown category finds nothing rather than everything.
            categoryIds: $categoryId === null ? [] : [$categoryId],
            featuredOnly: ($attributes['featured'] ?? false) === true,
            onSaleOnly: ($attributes['onSale'] ?? false) === true,
            sort: SearchSort::from($sort),
            offset: ($page - 1) * $perPage,
            limit: $perPage,
        ));
        $ids = $result->ids;
        $offers = $this->catalog->offers($ids);
        $urls = $this->links->products($ids);

        $products = [];
        foreach ($offers as $id => $offer) {
            $products[] = [
                'offer' => $offer,
                'simple' => $offer->isSimple(),
                'url' => $urls[$id] ?? '',
                'image' => get_the_post_thumbnail($id, 'medium', ['class' => 'orillacart-product-card__image', 'loading' => 'lazy']),
            ];
        }

        $base = self::baseUrl();
        $pages = (int) ceil($result->total / $perPage);
        $pageUrls = [];
        for ($number = 1; $number <= $pages; ++$number) {
            $pageUrls[$number] = $number === 1 ? remove_query_arg('product-page') : add_query_arg('product-page', $number);
        }

        return $this->templates->render('storefront/products.html.twig', [
            'products' => $products,
            'categories' => $fixedCategory !== '' ? [] : array_map(static fn(array $term): array => $term + [
                'url' => add_query_arg('category', $term['slug'], $base),
                'current' => $term['slug'] === $category,
            ], $this->categories->ordered()),
            'current_category' => $fixedCategory !== '' ? '' : $category,
            'all_url' => $base,
            'base_url' => $base,
            'search' => $search,
            'sort' => $sort,
            'sorts' => ($search === '' ? [] : ['relevance' => __('Best match', 'orillacart')]) + [
                'order' => __('Recommended', 'orillacart'),
                'newest' => __('Newest', 'orillacart'),
                'name' => __('Name', 'orillacart'),
                'price_asc' => __('Price: low to high', 'orillacart'),
                'price_desc' => __('Price: high to low', 'orillacart'),
            ],
            'page' => $page,
            'pages' => $pages,
            'page_urls' => $pageUrls,
            'columns' => $columns,
            'cart_url' => $this->links->pages()['cart'],
        ]);
    }

    private static function query(string $name): string
    {
        $value = $_GET[$name] ?? '';

        return \is_string($value) ? sanitize_text_field(wp_unslash($value)) : '';
    }

    /** This page without the shop's query arguments. */
    private static function baseUrl(): string
    {
        return remove_query_arg(['category', 'q', 'sort', 'product-page']);
    }
}
