<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Infrastructure\WordPress\Admin;

use OrillaCart\Catalog\Application\Dto\ProductOffer;
use OrillaCart\Catalog\Application\ProductService;
use OrillaCart\Catalog\Application\ShopCatalog;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductBrandTaxonomy;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductCategoryTaxonomy;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductPostType;
use OrillaCart\Shared\Domain\RuleViolation;
use OrillaCart\Shared\View\TemplateRenderer;
use OrillaCart\Shared\WordPress\AsHook;

/**
 * WordPress's list of products, as the old one was: a picture, SKU, price and order beside each name, filters by
 * category and brand, and the price in quick edit (the order is there already).
 */
#[AsHook('manage_' . ProductPostType::NAME . '_posts_columns', method: 'columns', filter: true)]
#[AsHook('manage_' . ProductPostType::NAME . '_posts_custom_column', method: 'column', arguments: 2)]
#[AsHook('manage_edit-' . ProductPostType::NAME . '_sortable_columns', method: 'sortable', filter: true)]
#[AsHook('restrict_manage_posts', method: 'filters')]
#[AsHook('quick_edit_custom_box', method: 'quickEdit', arguments: 2)]
#[AsHook('save_post_' . ProductPostType::NAME, method: 'saveQuickEdit', priority: 20)]
#[AsHook('admin_enqueue_scripts', method: 'scripts')]
final class ProductList
{
    private const string PRICE_FIELD = 'orillacart_price';

    /** @var array<int, ?ProductOffer> by product id, for the page being listed */
    private array $offers = [];

    public function __construct(
        private readonly ShopCatalog $catalog,
        private readonly ProductService $products,
        private readonly TemplateRenderer $templates,
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
     * post. Other saves carry no such field.
     */
    public function saveQuickEdit(int $postId): void
    {
        $price = $_POST[self::PRICE_FIELD] ?? null;
        if (!wp_doing_ajax() || ($_POST['action'] ?? null) !== 'inline-save' || !\is_string($price) || !current_user_can('edit_post', $postId)) {
            return;
        }
        $price = trim(str_replace(',', '.', $price));
        if ($price === '' || preg_match('/^\d{1,13}(\.\d{1,4})?$/', $price) !== 1) {
            return;
        }
        try {
            $this->products->changePrice($postId, $price);
        } catch (RuleViolation) {
            // A product whose details were never saved has no price to change.
        }
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
