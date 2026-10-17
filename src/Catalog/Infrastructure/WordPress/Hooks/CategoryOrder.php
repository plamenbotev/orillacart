<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Infrastructure\WordPress\Hooks;

use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductCategoryTaxonomy;
use OrillaCart\Shared\WordPress\AsHook;

/**
 * The Order field of product categories: the storefront lists categories by it, lowest first, then by name.
 * Kept in term meta, which the REST API also offers.
 */
#[AsHook('init', method: 'registerMeta')]
#[AsHook(ProductCategoryTaxonomy::NAME . '_add_form_fields', method: 'addField')]
#[AsHook(ProductCategoryTaxonomy::NAME . '_edit_form_fields', method: 'editField')]
#[AsHook('created_' . ProductCategoryTaxonomy::NAME, method: 'save')]
#[AsHook('edited_' . ProductCategoryTaxonomy::NAME, method: 'save')]
#[AsHook('manage_edit-' . ProductCategoryTaxonomy::NAME . '_columns', method: 'columns', filter: true)]
#[AsHook('manage_' . ProductCategoryTaxonomy::NAME . '_custom_column', method: 'column', filter: true, arguments: 3)]
final readonly class CategoryOrder
{
    public const string META = 'orillacart_position';
    private const string FIELD = 'orillacart_position';
    /** The list's column; not the field's name, since WordPress uses it as the header cell's id. */
    private const string COLUMN = 'orillacart_order';

    public static function position(int $termId): int
    {
        $value = get_term_meta($termId, self::META, true);

        return is_numeric($value) ? (int) $value : 0;
    }

    public function registerMeta(): void
    {
        register_term_meta(ProductCategoryTaxonomy::NAME, self::META, [
            'type' => 'integer',
            'single' => true,
            'default' => 0,
            'show_in_rest' => true,
            'sanitize_callback' => static fn(mixed $value): int => is_numeric($value) ? (int) $value : 0,
        ]);
    }

    public function addField(): void
    {
        printf(
            '<div class="form-field"><label for="%1$s">%2$s</label><input type="number" step="1" id="%1$s" name="%1$s" value="0"><p>%3$s</p></div>',
            esc_attr(self::FIELD),
            esc_html__('Order', 'orillacart'),
            esc_html__('The shop lists categories by this number, lowest first, then by name.', 'orillacart'),
        );
    }

    public function editField(\WP_Term $term): void
    {
        printf(
            '<tr class="form-field"><th scope="row"><label for="%1$s">%2$s</label></th><td><input type="number" step="1" id="%1$s" name="%1$s" value="%3$d"><p class="description">%4$s</p></td></tr>',
            esc_attr(self::FIELD),
            esc_html__('Order', 'orillacart'),
            self::position($term->term_id),
            esc_html__('The shop lists categories by this number, lowest first, then by name.', 'orillacart'),
        );
    }

    /**
     * Saves the field from the category screens, whose forms WordPress checked (nonce and capability) before
     * it made or changed the term. Terms made elsewhere carry no field and keep their order.
     */
    public function save(int $termId): void
    {
        // WordPress checked the nonce of the category forms before calling this.
        $value = $_POST[self::FIELD] ?? null;
        if (!is_numeric($value) || !current_user_can('edit_term', $termId)) {
            return;
        }
        update_term_meta($termId, self::META, (int) $value);
    }

    /**
     * @param array<string, string> $columns
     *
     * @return array<string, string>
     */
    public function columns(array $columns): array
    {
        return $columns + [self::COLUMN => __('Order', 'orillacart')];
    }

    public function column(string $content, string $column, int $termId): string
    {
        return $column === self::COLUMN ? (string) self::position($termId) : $content;
    }
}
