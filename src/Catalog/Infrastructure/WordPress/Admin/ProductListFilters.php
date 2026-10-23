<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Infrastructure\WordPress\Admin;

use OrillaCart\Catalog\Application\AttributeSetService;
use OrillaCart\Catalog\Application\Dto\AttributeSetView;
use OrillaCart\Catalog\Application\Dto\ProductFilter;
use OrillaCart\Catalog\Application\ProductFinder;
use OrillaCart\Catalog\Domain\ProductCriteria;
use OrillaCart\Catalog\Domain\ProductRepository;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductBrandTaxonomy;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductCategoryTaxonomy;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductPostType;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductTagTaxonomy;
use OrillaCart\Inventory\Application\Dto\StockroomView;
use OrillaCart\Inventory\Application\StockroomService;
use OrillaCart\Shared\WordPress\AsHook;
use OrillaCart\Shipping\Application\ShippingClassService;

/**
 * Finding a product on WordPress's list of them: the search box matches SKUs (variations' too) and product
 * numbers besides names, and filters narrow by category, brand and tag, kind, stock (in a stockroom), what is
 * shown, price, shipping class, attribute set, and what is still missing. Every filter is in the address.
 */
#[AsHook('restrict_manage_posts', method: 'filters')]
#[AsHook('pre_get_posts', method: 'narrow')]
#[AsHook('posts_search', method: 'search', filter: true, arguments: 2)]
#[AsHook('admin_enqueue_scripts', method: 'styles')]
final class ProductListFilters
{
    private const string KIND = 'orillacart_kind';
    private const string STOCK = 'orillacart_stock';
    private const string STOCKROOM = 'orillacart_stockroom';
    private const string SHOWN = 'orillacart_shown';
    private const string MISSING = 'orillacart_missing';
    private const string MIN_PRICE = 'orillacart_min_price';
    private const string MAX_PRICE = 'orillacart_max_price';
    private const string SHIPPING_CLASS = 'orillacart_class';
    private const string ATTRIBUTE_SET = 'orillacart_set';
    /** What the product's content misses, not its commerce details. */
    private const string NO_PICTURE = 'picture';
    private const string NO_CATEGORY = 'category';
    private const string PRICE = '/^\d{1,11}(\.\d{1,4})?$/';

    public function __construct(
        private readonly ProductFinder $finder,
        private readonly ProductRepository $products,
        private readonly StockroomService $stockrooms,
        private readonly ShippingClassService $shippingClasses,
        private readonly AttributeSetService $attributeSets,
    ) {}

    public function filters(string $postType): void
    {
        if ($postType !== ProductPostType::NAME) {
            return;
        }
        foreach ([
            ProductCategoryTaxonomy::NAME => __('All categories', 'orillacart'),
            ProductBrandTaxonomy::NAME => __('All brands', 'orillacart'),
            ProductTagTaxonomy::NAME => __('All tags', 'orillacart'),
        ] as $taxonomy => $all) {
            $chosen = $_GET[$taxonomy] ?? '';
            wp_dropdown_categories([
                'taxonomy' => $taxonomy,
                'name' => $taxonomy,
                'value_field' => 'slug',
                // "None" with an empty value, not "all": WordPress prints the "all" option only when there are terms.
                'show_option_none' => $all,
                'option_none_value' => '',
                'hierarchical' => true,
                'hide_empty' => false,
                'selected' => \is_string($chosen) ? sanitize_title($chosen) : '',
            ]);
        }
        self::select(self::KIND, __('Filter by kind', 'orillacart'), self::kinds(), __('All kinds', 'orillacart'));
        self::select(self::STOCK, __('Filter by stock', 'orillacart'), self::stockStates(), __('Any stock', 'orillacart'));
        $stockrooms = $this->stockrooms->stockrooms();
        if (\count($stockrooms) > 1) {
            self::select(self::STOCKROOM, __('Count stock in', 'orillacart'), array_column(array_map(static fn(StockroomView $room): array => [$room->id, $room->name], $stockrooms), 1, 0), __('All stockrooms', 'orillacart'));
        }
        self::select(self::SHOWN, __('Filter by what is shown', 'orillacart'), self::shown(), __('On sale, featured…', 'orillacart'));
        self::select(self::MISSING, __('Filter by what is missing', 'orillacart'), self::missing(), __('Missing anything', 'orillacart'));

        $more = [self::MIN_PRICE, self::MAX_PRICE, self::SHIPPING_CLASS, self::ATTRIBUTE_SET];
        $open = array_filter($more, static fn(string $name): bool => self::text($name) !== '') !== [];
        printf('<details class="orillacart-more-filters"%s><summary>%s</summary>', $open ? ' open' : '', esc_html__('More filters', 'orillacart'));
        foreach ([self::MIN_PRICE => __('Price from', 'orillacart'), self::MAX_PRICE => __('Price to', 'orillacart')] as $name => $label) {
            printf(
                '<label for="%1$s" class="screen-reader-text">%2$s</label><input type="text" id="%1$s" name="%1$s" value="%3$s" placeholder="%2$s" inputmode="decimal" size="8">',
                esc_attr($name),
                esc_html($label),
                esc_attr(preg_match(self::PRICE, self::text($name)) === 1 ? self::text($name) : ''),
            );
        }
        $classes = ['0' => __('No shipping class', 'orillacart')];
        foreach ($this->shippingClasses->classes() as $class) {
            $classes[(string) $class->id] = $class->name;
        }
        self::select(self::SHIPPING_CLASS, __('Filter by shipping class', 'orillacart'), $classes, __('Any shipping class', 'orillacart'));
        self::select(self::ATTRIBUTE_SET, __('Filter by attribute set', 'orillacart'), array_column(array_map(static fn(AttributeSetView $set): array => [$set->id, $set->name], $this->attributeSets->sets()), 1, 0), __('Any attribute set', 'orillacart'));
        echo '</details>';
        $filters = [self::KIND, self::STOCK, self::STOCKROOM, self::SHOWN, self::MISSING, ...$more, ProductCategoryTaxonomy::NAME, ProductBrandTaxonomy::NAME, ProductTagTaxonomy::NAME, 's'];
        if (array_filter($filters, static fn(string $name): bool => self::text($name) !== '') !== []) {
            printf('<a class="orillacart-clear-filters" href="%s">%s</a>', esc_url(admin_url('edit.php?post_type=' . ProductPostType::NAME)), esc_html__('Clear filters', 'orillacart'));
        }
    }

    /**
     * Narrows WordPress's query of the list to the products the filters find.
     */
    public function narrow(\WP_Query $query): void
    {
        if (!self::isTheList($query)) {
            return;
        }
        $missing = self::chosen(self::MISSING, self::missing());
        if ($missing === self::NO_PICTURE) {
            $query->set('meta_query', [...self::queryList($query->get('meta_query')), ['key' => '_thumbnail_id', 'compare' => 'NOT EXISTS']]);
        }
        if ($missing === self::NO_CATEGORY) {
            $query->set('tax_query', [...self::queryList($query->get('tax_query')), ['taxonomy' => ProductCategoryTaxonomy::NAME, 'operator' => 'NOT EXISTS']]);
        }
        $stockroom = self::text(self::STOCKROOM);
        $class = self::text(self::SHIPPING_CLASS);
        $set = self::text(self::ATTRIBUTE_SET);
        $ids = $this->finder->ids(new ProductFilter(
            self::chosen(self::KIND, self::kinds()),
            self::chosen(self::STOCK, self::stockStates()),
            ctype_digit($stockroom) && (int) $stockroom > 0 ? (int) $stockroom : null,
            self::chosen(self::SHOWN, self::shown()),
            self::price(self::MIN_PRICE),
            self::price(self::MAX_PRICE),
            ctype_digit($class) ? (int) $class : null,
            ctype_digit($set) && (int) $set > 0 ? (int) $set : null,
            \in_array($missing, ProductCriteria::MISSING, true) ? $missing : null,
        ));
        if ($ids === null) {
            return;
        }
        $already = $query->get('post__in');
        if (\is_array($already) && $already !== []) {
            $ids = array_values(array_intersect($ids, array_map(static fn(mixed $id): int => is_numeric($id) ? (int) $id : 0, $already)));
        }
        // None found lists none, not all.
        $query->set('post__in', $ids === [] ? [0] : $ids);
    }

    /**
     * The search box finds products by SKU, the product's or a variation's, and by number, besides by name.
     */
    public function search(string $search, \WP_Query $query): string
    {
        $text = $query->get('s');
        if ($search === '' || !\is_string($text) || trim($text) === '' || !self::isTheList($query) || !str_starts_with($search, ' AND ')) {
            return $search;
        }
        $text = trim($text);
        $ids = $this->products->idsWithSku($text);
        if (ctype_digit($text) && \strlen($text) <= 18) {
            $ids[] = (int) $text;
        }
        if ($ids === []) {
            return $search;
        }
        global $wpdb;
        if (!$wpdb instanceof \wpdb) {
            return $search;
        }

        return \sprintf(' AND (%s OR %s.ID IN (%s))', substr($search, 5), $wpdb->posts, implode(',', array_unique($ids)));
    }

    public function styles(string $hook): void
    {
        if ($hook !== 'edit.php' || get_current_screen()?->post_type !== ProductPostType::NAME) {
            return;
        }
        wp_add_inline_style('list-tables', '.orillacart-more-filters{display:inline-block;vertical-align:middle;margin:0 8px 0 0}.orillacart-more-filters summary{cursor:pointer;line-height:30px}.orillacart-more-filters[open]{display:block;margin:4px 0}.orillacart-more-filters input{margin:0 4px 0 0}.orillacart-clear-filters{display:inline-block;line-height:30px;margin-right:8px}');
    }

    /** @return array<string, string> */
    private static function kinds(): array
    {
        return [
            ProductCriteria::SHIPS => __('Ships', 'orillacart'),
            ProductCriteria::DOES_NOT_SHIP => __('Does not ship', 'orillacart'),
            ProductCriteria::WITH_VARIATIONS => __('With variations', 'orillacart'),
            ProductCriteria::BUNDLE => __('Bundle', 'orillacart'),
            ProductCriteria::WITH_DOWNLOADS => __('With download files', 'orillacart'),
        ];
    }

    /** @return array<string, string> */
    private static function stockStates(): array
    {
        return [
            ProductFinder::IN_STOCK => __('In stock', 'orillacart'),
            ProductFinder::LOW_STOCK => __('Low stock', 'orillacart'),
            ProductFinder::SOME_OUT => __('Partly out of stock', 'orillacart'),
            ProductFinder::OUT_OF_STOCK => __('Out of stock', 'orillacart'),
            ProductFinder::OWED => __('Sold below zero (backorders)', 'orillacart'),
            ProductFinder::ALWAYS_AVAILABLE => __('Always available (not counted)', 'orillacart'),
            ProductFinder::NOT_FOR_SALE => __('Not for sale', 'orillacart'),
        ];
    }

    /** @return array<string, string> */
    private static function shown(): array
    {
        return [
            ProductCriteria::ON_SALE => __('On sale now', 'orillacart'),
            ProductCriteria::FEATURED => __('Featured', 'orillacart'),
            ProductCriteria::PRICE_HIDDEN => __('Price hidden', 'orillacart'),
            ProductCriteria::NOT_PURCHASABLE => __('Not purchasable', 'orillacart'),
        ];
    }

    /** @return array<string, string> */
    private static function missing(): array
    {
        return [
            ProductCriteria::NO_SKU => __('Missing a SKU', 'orillacart'),
            self::NO_PICTURE => __('Missing a picture', 'orillacart'),
            self::NO_CATEGORY => __('Missing a category', 'orillacart'),
            ProductCriteria::NO_PRICE => __('Missing a price (zero)', 'orillacart'),
            ProductCriteria::NO_WEIGHT => __('Missing a weight (ships)', 'orillacart'),
        ];
    }

    /** @param array<int|string, string> $choices */
    private static function select(string $name, string $label, array $choices, string $all): void
    {
        $chosen = self::text($name);
        $options = \sprintf('<option value="">%s</option>', esc_html($all));
        foreach ($choices as $value => $text) {
            $options .= \sprintf('<option value="%s"%s>%s</option>', esc_attr((string) $value), selected($chosen, (string) $value, false), esc_html($text));
        }
        printf('<label for="%1$s" class="screen-reader-text">%2$s</label><select id="%1$s" name="%1$s">%3$s</select>', esc_attr($name), esc_html($label), $options);
    }

    /** @param array<string, string> $choices */
    private static function chosen(string $name, array $choices): ?string
    {
        $value = self::text($name);

        return \array_key_exists($value, $choices) ? $value : null;
    }

    private static function price(string $name): ?string
    {
        $value = str_replace(',', '.', self::text($name));

        return preg_match(self::PRICE, $value) === 1 ? $value : null;
    }

    private static function text(string $name): string
    {
        $value = $_GET[$name] ?? '';

        return \is_string($value) ? sanitize_text_field(wp_unslash($value)) : '';
    }

    /** @return list<mixed> */
    private static function queryList(mixed $clauses): array
    {
        return \is_array($clauses) ? array_values($clauses) : [];
    }

    private static function isTheList(\WP_Query $query): bool
    {
        return is_admin() && $query->is_main_query() && $query->get('post_type') === ProductPostType::NAME
            && (\function_exists('get_current_screen') ? get_current_screen()?->id === 'edit-' . ProductPostType::NAME : false);
    }
}
