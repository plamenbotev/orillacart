<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Infrastructure\WordPress\Admin;

use OrillaCart\Catalog\Application\BulkEdits;
use OrillaCart\Catalog\Application\Dto\BulkEdit;
use OrillaCart\Catalog\Application\Dto\ProductOffer;
use OrillaCart\Catalog\Application\ProductService;
use OrillaCart\Catalog\Application\ShopCatalog;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductBrandTaxonomy;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductCategoryTaxonomy;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductPostType;
use OrillaCart\Inventory\Application\StockroomService;
use OrillaCart\Shared\Domain\RuleViolation;
use OrillaCart\Shared\Security\Capability;
use OrillaCart\Shared\View\TemplateRenderer;
use OrillaCart\Shared\WordPress\AsHook;

/**
 * WordPress's list of products, as the old one was: a picture, SKU, price and order beside each name, filters by
 * category and brand, and the price in quick edit (the order is there already). Bulk edit changes prices, sales
 * and stock (BulkEdits).
 */
#[AsHook('manage_' . ProductPostType::NAME . '_posts_columns', method: 'columns', filter: true)]
#[AsHook('manage_' . ProductPostType::NAME . '_posts_custom_column', method: 'column', arguments: 2)]
#[AsHook('manage_edit-' . ProductPostType::NAME . '_sortable_columns', method: 'sortable', filter: true)]
#[AsHook('restrict_manage_posts', method: 'filters')]
#[AsHook('quick_edit_custom_box', method: 'quickEdit', arguments: 2)]
#[AsHook('save_post_' . ProductPostType::NAME, method: 'saveQuickEdit', priority: 20)]
#[AsHook('admin_enqueue_scripts', method: 'scripts')]
#[AsHook('bulk_edit_custom_box', method: 'bulkEdit', arguments: 2)]
#[AsHook('bulk_edit_posts', method: 'saveBulkEdit', arguments: 2)]
#[AsHook('admin_notices', method: 'bulkNotice')]
final class ProductList
{
    private const string PRICE_FIELD = 'orillacart_price';
    private const string BULK = 'orillacart_bulk';
    /** What a bulk edit left as it was, for the list the browser comes back to. */
    private const string NOTICE = 'orillacart_bulk_refused_';

    /** @var array<int, ?ProductOffer> by product id, for the page being listed */
    private array $offers = [];

    public function __construct(
        private readonly ShopCatalog $catalog,
        private readonly ProductService $products,
        private readonly TemplateRenderer $templates,
        private readonly BulkEdits $bulk,
        private readonly StockroomService $stockrooms,
    ) {}

    /**
     * @param array<string, string> $columns
     *
     * @return array<string, string>
     */
    public function columns(array $columns): array
    {
        $listed = [];
        foreach ($columns as $key => $label) {
            if ($key === 'title') {
                $listed['orillacart_image'] = '<span class="dashicons dashicons-format-image" aria-hidden="true"></span><span class="screen-reader-text">' . esc_html__('Image', 'orillacart') . '</span>';
            }
            $listed[$key] = $label;
            if ($key === 'title') {
                $listed['orillacart_sku'] = __('SKU', 'orillacart');
                $listed['orillacart_price'] = __('Price', 'orillacart');
                $listed['orillacart_order'] = __('Order', 'orillacart');
            }
        }

        return $listed;
    }

    public function column(string $column, int $postId): void
    {
        switch ($column) {
            case 'orillacart_image':
                echo get_the_post_thumbnail($postId, [40, 40]);
                break;
            case 'orillacart_sku':
                echo esc_html($this->offer($postId)->sku ?? '');
                break;
            case 'orillacart_price':
                $offer = $this->offer($postId);
                if ($offer === null) {
                    echo '—';
                    break;
                }
                echo $this->templates->render('storefront/_list_price.html.twig', ['offer' => $offer]);
                // For quick edit, which fills its field from the row.
                printf('<span hidden data-orillacart-price="%s"></span>', esc_attr($offer->regularPrice ?? $offer->price));
                break;
            case 'orillacart_order':
                echo (int) get_post_field('menu_order', $postId);
                break;
        }
    }

    /**
     * @param array<string, string|array<int, string|bool>> $columns
     *
     * @return array<string, string|array<int, string|bool>>
     */
    public function sortable(array $columns): array
    {
        return $columns + ['orillacart_order' => 'menu_order'];
    }

    public function filters(string $postType): void
    {
        if ($postType !== ProductPostType::NAME) {
            return;
        }
        foreach ([ProductCategoryTaxonomy::NAME => __('All categories', 'orillacart'), ProductBrandTaxonomy::NAME => __('All brands', 'orillacart')] as $taxonomy => $all) {
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
    }

    public function quickEdit(string $column, string $postType): void
    {
        if ($postType !== ProductPostType::NAME || $column !== 'orillacart_price') {
            return;
        }
        printf(
            '<fieldset class="inline-edit-col-right"><div class="inline-edit-col"><label><span class="title">%s</span><span class="input-text-wrap"><input type="text" name="%s" inputmode="decimal" value=""></span></label></div></fieldset>',
            esc_html__('Price', 'orillacart'),
            esc_attr(self::PRICE_FIELD),
        );
    }

    /**
     * Saves the price from quick edit, whose request WordPress checked (nonce and capability) before saving the
     * post. Other saves carry no such field. A price that is not one is refused where quick edit shows its errors:
     * WordPress puts what the request ends with under the fields when it is not the row.
     */
    public function saveQuickEdit(int $postId): void
    {
        $price = $_POST[self::PRICE_FIELD] ?? null;
        if (!wp_doing_ajax() || ($_POST['action'] ?? null) !== 'inline-save' || !\is_string($price) || !current_user_can('edit_post', $postId)) {
            return;
        }
        $price = trim(str_replace(',', '.', $price));
        if ($price === '') {
            return;
        }
        if (preg_match('/^\d{1,13}(\.\d{1,4})?$/', $price) !== 1) {
            wp_die(esc_html__('The price was not saved: use a number such as 19.90.', 'orillacart'));
        }
        try {
            $this->products->changePrice($postId, $price);
        } catch (RuleViolation $e) {
            wp_die(esc_html(\sprintf(
                /* translators: %s: why the price was refused */
                __('The price was not saved: %s', 'orillacart'),
                $e->getMessage(),
            )));
        }
    }

    /** Bulk edit's own fields: the price, the sale and the stock in a stockroom, each left as it is by default. */
    public function bulkEdit(string $column, string $postType): void
    {
        if ($postType !== ProductPostType::NAME || $column !== 'orillacart_price') {
            return;
        }
        $select = self::select(...);
        $input = static fn(string $name, string $mode = 'decimal'): string => \sprintf('<input type="text" name="%s[%s]" inputmode="%s" size="8" value="">', self::BULK, esc_attr($name), esc_attr($mode));
        $rooms = ['' => __('— Stockroom —', 'orillacart')];
        foreach ($this->stockrooms->stockrooms() as $room) {
            $rooms[$room->id] = $room->name;
        }
        printf(
            '<fieldset class="inline-edit-col-right"><div class="inline-edit-col"><label class="alignleft"><span class="title">%s</span>%s %s</label><label class="alignleft"><span class="title">%s</span>%s %s</label><label class="alignleft"><span class="title">%s</span>%s %s %s</label><p class="howto">%s</p></div></fieldset>',
            esc_html__('Price', 'orillacart'),
            $select('price', ['' => __('— No change —', 'orillacart'), 'set' => __('Set to', 'orillacart'), 'raise' => __('Raise by', 'orillacart'), 'lower' => __('Lower by', 'orillacart'), 'raise_percent' => __('Raise by %', 'orillacart'), 'lower_percent' => __('Lower by %', 'orillacart')]),
            $input('price_by'),
            esc_html__('Sale', 'orillacart'),
            $select('sale', ['' => __('— No change —', 'orillacart'), 'set' => __('Set to', 'orillacart'), 'percent_off' => __('% off the price', 'orillacart'), 'remove' => __('End the sale', 'orillacart')]),
            $input('sale_by'),
            esc_html__('Stock', 'orillacart'),
            $select('stockroom', $rooms),
            $select('stock', ['' => __('— No change —', 'orillacart'), 'set' => __('Set to', 'orillacart'), 'add' => __('Add', 'orillacart')]),
            $input('stock_by', 'numeric'),
            esc_html__('Products with variations change each variation, from its own price and stock.', 'orillacart'),
        );
    }

    /**
     * Applies bulk edit's own fields to the products WordPress just saved, whose request it checked (nonce and
     * capability). What could not be changed is told on the list the browser comes back to.
     *
     * @param list<int>            $updated
     * @param array<string, mixed> $shared
     */
    public function saveBulkEdit(array $updated, array $shared): void
    {
        $fields = $_REQUEST[self::BULK] ?? null;
        if (!\is_array($fields) || ($shared['post_type'] ?? ProductPostType::NAME) !== ProductPostType::NAME || !current_user_can(Capability::MANAGE_SHOP)) {
            return;
        }
        $fields = wp_unslash($fields);
        $text = static fn(string $name): string => \is_array($fields) && \is_string($fields[$name] ?? null) ? trim(str_replace(',', '.', $fields[$name])) : '';
        $edit = new BulkEdit(
            $text('price') === '' ? null : $text('price'),
            $text('price_by'),
            $text('sale') === '' ? null : $text('sale'),
            $text('sale_by'),
            $text('stock') === '' ? null : $text('stock'),
            ctype_digit($text('stockroom')) ? (int) $text('stockroom') : null,
            preg_match('/^-?\d{1,9}$/', $text('stock_by')) === 1 ? (int) $text('stock_by') : 0,
        );
        if ($edit->price === null && $edit->sale === null && $edit->stock === null) {
            return;
        }
        $products = array_values(array_filter(array_map(intval(...), $updated), static fn(int $id): bool => get_post_type($id) === ProductPostType::NAME));
        try {
            $refused = $this->bulk->apply($products, $edit);
        } catch (RuleViolation $e) {
            $refused = [$e->getMessage()];
        }
        if ($refused !== []) {
            set_transient(self::NOTICE . get_current_user_id(), $refused, MINUTE_IN_SECONDS * 5);
        }
    }

    public function bulkNotice(): void
    {
        if (get_current_screen()?->id !== 'edit-' . ProductPostType::NAME) {
            return;
        }
        $key = self::NOTICE . get_current_user_id();
        $refused = get_transient($key);
        if (!\is_array($refused) || $refused === []) {
            return;
        }
        delete_transient($key);
        printf(
            '<div class="notice notice-warning is-dismissible"><p>%s</p><ul>%s</ul></div>',
            esc_html__('Bulk edit left some products as they were:', 'orillacart'),
            implode('', array_map(static fn(mixed $line): string => '<li>' . esc_html(\is_string($line) ? $line : '') . '</li>', $refused)),
        );
    }

    /** @param array<int|string, string> $choices */
    private static function select(string $name, array $choices): string
    {
        $options = '';
        foreach ($choices as $value => $label) {
            $options .= \sprintf('<option value="%s">%s</option>', esc_attr((string) $value), esc_html($label));
        }

        return \sprintf('<select name="%s[%s]">%s</select>', self::BULK, esc_attr($name), $options);
    }

    public function scripts(string $hook): void
    {
        if ($hook !== 'edit.php' || get_current_screen()?->post_type !== ProductPostType::NAME) {
            return;
        }
        // Quick edit copies the row's price into its field when it opens.
        wp_add_inline_script('inline-edit-post', <<<'JS'
            ( function () {
                const edit = window.inlineEditPost && window.inlineEditPost.edit;
                if ( ! edit ) { return; }
                window.inlineEditPost.edit = function ( id ) {
                    edit.apply( this, arguments );
                    const postId = typeof id === 'object' ? this.getId( id ) : id;
                    const price = document.querySelector( '#post-' + postId + ' [data-orillacart-price]' );
                    const field = document.querySelector( '#edit-' + postId + ' input[name="orillacart_price"]' );
                    if ( price && field ) { field.value = price.dataset.orillacartPrice; }
                };
            } )();
            JS);
        // Beside "Add New": the products file out and back in.
        wp_add_inline_script('inline-edit-post', \sprintf(
            '( function () { const add = document.querySelector( ".wrap .page-title-action" ); if ( ! add ) { return; } const link = document.createElement( "a" ); link.className = "page-title-action"; link.href = %s; link.textContent = %s; add.after( link ); } )();',
            wp_json_encode(admin_url('admin.php?page=orillacart-exchange')),
            wp_json_encode(__('Import and export', 'orillacart')),
        ));
        wp_add_inline_style('list-tables', '.column-orillacart_image{width:52px}.column-orillacart_order{width:5em}.column-orillacart_sku{width:10em}');
    }

    private function offer(int $id): ?ProductOffer
    {
        if (!\array_key_exists($id, $this->offers)) {
            global $wp_query;
            // One query for the whole page of rows.
            $ids = [];
            foreach ($wp_query instanceof \WP_Query ? $wp_query->posts ?? [] : [] as $post) {
                if ($post instanceof \WP_Post) {
                    $ids[] = $post->ID;
                }
            }
            $offers = $this->catalog->offers(array_values(array_unique([$id, ...$ids])));
            foreach (array_unique([$id, ...$ids]) as $listed) {
                $this->offers[$listed] = $offers[$listed] ?? null;
            }
        }

        return $this->offers[$id];
    }
}
