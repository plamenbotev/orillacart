<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress\Blocks;

use OrillaCart\Catalog\Application\ShopCatalog;
use OrillaCart\Catalog\Infrastructure\WordPress\ProductCategories;
use OrillaCart\Shared\View\TemplateRenderer;
use OrillaCart\Shared\WordPress\BlockDefinition;
use OrillaCart\Storefront\Infrastructure\WordPress\ShopLinks;
use OrillaCart\Storefront\Infrastructure\WordPress\ShopListing;
use OrillaCart\Storefront\Infrastructure\WordPress\Shopper;

/**
 * The shop: published products in a grid, which visitors narrow by category, words, brand, the shop's filters,
 * price and stock, and sort. The products and the counts come from the search index; their cards show the
 * catalog's offers as they are now. The query string carries the choices (ShopListing), so pages can be linked
 * and work without the script. The filters show here, or (filters off) in a Product filters block elsewhere.
 */
final readonly class ProductsBlock implements BlockDefinition
{
    public const array SORTS = ShopListing::SORTS;

    public function __construct(
        private ShopCatalog $catalog,
        private ShopListing $listing,
        private ProductCategories $categories,
        private ShopLinks $links,
        private TemplateRenderer $templates,
        private Shopper $shopper,
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
                'filters' => ['type' => 'boolean', 'default' => true],
            ],
        ];
    }

    public function render(array $attributes): string
    {
        $perPage = max(1, min(60, \is_int($attributes['perPage'] ?? null) ? $attributes['perPage'] : 12));
        $columns = max(1, min(6, \is_int($attributes['columns'] ?? null) ? $attributes['columns'] : 3));
        $fixedCategory = \is_string($attributes['category'] ?? null) ? sanitize_title($attributes['category']) : '';
        $showFilters = ($attributes['filters'] ?? true) !== false;

        $choices = $this->listing->choices($fixedCategory, \is_string($attributes['sort'] ?? null) ? $attributes['sort'] : 'order');
        $result = $this->listing->search(
            $choices,
            ($choices['page'] - 1) * $perPage,
            $perPage,
            $showFilters,
            ($attributes['featured'] ?? false) === true,
            ($attributes['onSale'] ?? false) === true,
        );
        $panel = $this->listing->panel($choices, $result);
        $ids = $result->ids;
        $offers = $this->catalog->offers($ids, $this->shopper->customerId());
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

        $base = remove_query_arg(['category', 'q', 'sort', 'product-page', ...ShopListing::FILTER_ARGUMENTS]);
        $pages = (int) ceil($result->total / $perPage);
        $pageUrls = [];
        for ($number = 1; $number <= $pages; ++$number) {
            $pageUrls[$number] = $number === 1 ? remove_query_arg('product-page') : add_query_arg('product-page', $number);
        }

        return $this->templates->render('storefront/products.html.twig', [
            'products' => $products,
            'categories' => $fixedCategory !== '' ? [] : array_map(static fn(array $term): array => $term + [
                'url' => add_query_arg('category', $term['slug'], $base),
                'current' => $term['slug'] === $choices['category'],
            ], $this->categories->ordered()),
            'current_category' => $fixedCategory !== '' ? '' : $choices['category'],
            'all_url' => $base,
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
            'cart_url' => $this->links->pages()['cart'],
            'show_filters' => $showFilters,
            'panel' => $panel,
            // Without its own panel, the form keeps what a Product filters block chose.
            'hidden' => $showFilters ? [] : $this->listing->hidden($choices, true),
            'filtered' => $panel['filtered'],
        ]);
    }
}
