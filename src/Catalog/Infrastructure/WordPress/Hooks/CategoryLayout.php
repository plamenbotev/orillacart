<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Infrastructure\WordPress\Hooks;

use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductCategoryTaxonomy;
use OrillaCart\Shared\WordPress\AsHook;

/**
 * The "Shown as" field of product categories: their products in a grid of cards or a list of rows, or as the
 * category above them shows them (in the end, as the Products block does). The shopper may still switch.
 */
#[AsHook('init', method: 'registerMeta')]
#[AsHook(ProductCategoryTaxonomy::NAME . '_add_form_fields', method: 'addField')]
#[AsHook(ProductCategoryTaxonomy::NAME . '_edit_form_fields', method: 'editField')]
#[AsHook('created_' . ProductCategoryTaxonomy::NAME, method: 'save')]
#[AsHook('edited_' . ProductCategoryTaxonomy::NAME, method: 'save')]
final readonly class CategoryLayout
{
    public const string META = 'orillacart_layout';
    public const array LAYOUTS = ['grid', 'list'];
    private const string FIELD = 'orillacart_layout';

    /** The layout the category, or the nearest one above it, chose; null for the Products block's. */
    public static function of(int $termId): ?string
    {
        foreach ([$termId, ...get_ancestors($termId, ProductCategoryTaxonomy::NAME, 'taxonomy')] as $id) {
            $value = get_term_meta((int) $id, self::META, true);
            if (\in_array($value, self::LAYOUTS, true)) {
                return $value;
            }
        }

        return null;
    }

    public function registerMeta(): void
    {
        register_term_meta(ProductCategoryTaxonomy::NAME, self::META, [
            'type' => 'string',
            'single' => true,
            'default' => '',
            'show_in_rest' => true,
            'sanitize_callback' => static fn(mixed $value): string => \in_array($value, self::LAYOUTS, true) ? $value : '',
        ]);
    }

    public function addField(): void
    {
        printf('<div class="form-field"><label for="%1$s">%2$s</label>%3$s<p>%4$s</p></div>', esc_attr(self::FIELD), esc_html__('Shown as', 'orillacart'), self::select(''), esc_html(self::help()));
    }

    public function editField(\WP_Term $term): void
    {
        $value = get_term_meta($term->term_id, self::META, true);
        printf(
            '<tr class="form-field"><th scope="row"><label for="%1$s">%2$s</label></th><td>%3$s<p class="description">%4$s</p></td></tr>',
            esc_attr(self::FIELD),
            esc_html__('Shown as', 'orillacart'),
            self::select(\is_string($value) ? $value : ''),
            esc_html(self::help()),
        );
    }

    /**
     * Saves the field from the category screens, whose forms WordPress checked (nonce and capability) before
     * it made or changed the term. Terms made elsewhere carry no field and keep their layout.
     */
    public function save(int $termId): void
    {
        // WordPress checked the nonce of the category forms before calling this.
        $value = $_POST[self::FIELD] ?? null;
        if (!\is_string($value) || !current_user_can('edit_term', $termId)) {
            return;
        }
        if (\in_array($value, self::LAYOUTS, true)) {
            update_term_meta($termId, self::META, $value);
        } else {
            delete_term_meta($termId, self::META);
        }
    }

    private static function select(string $value): string
    {
        $options = ['' => __('As the category above it, or the shop', 'orillacart'), 'grid' => __('A grid of cards', 'orillacart'), 'list' => __('A list of rows', 'orillacart')];
        $html = '';
        foreach ($options as $option => $label) {
            $html .= \sprintf('<option value="%s"%s>%s</option>', esc_attr($option), selected($value, $option, false), esc_html($label));
        }

        return \sprintf('<select id="%1$s" name="%1$s">%2$s</select>', esc_attr(self::FIELD), $html);
    }

    private static function help(): string
    {
        return __('How the category\'s products are listed: pictures in a grid, or rows with a short description. Shoppers can switch.', 'orillacart');
    }
}
