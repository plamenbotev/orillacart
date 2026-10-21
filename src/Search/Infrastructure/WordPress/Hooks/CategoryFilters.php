<?php

declare(strict_types=1);

namespace OrillaCart\Search\Infrastructure\WordPress\Hooks;

use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductCategoryTaxonomy;
use OrillaCart\Search\Application\SearchFilters;
use OrillaCart\Search\Application\ShopSearch;
use OrillaCart\Shared\WordPress\AsHook;

/**
 * The "Filters here" field of a product category: the order of the shop's filters among its products. A filter
 * left empty takes the shop's order, after those numbered; a category that numbers none takes the order of the
 * nearest category above it that does, or the shop's.
 */
#[AsHook(ProductCategoryTaxonomy::NAME . '_edit_form_fields', method: 'editField')]
#[AsHook('edited_' . ProductCategoryTaxonomy::NAME, method: 'save')]
final readonly class CategoryFilters
{
    private const string FIELD = 'orillacart_filter_order';

    public function __construct(
        private ShopSearch $search,
        private SearchFilters $filters,
    ) {}

    public function editField(\WP_Term $term): void
    {
        $above = array_values(array_map(intval(...), get_ancestors($term->term_id, ProductCategoryTaxonomy::NAME, 'taxonomy')));
        $shown = $this->search->filters($term->term_id, $above);
        if ($shown === []) {
            return;
        }
        $places = $this->filters->orderIn($term->term_id);
        $rows = '';
        foreach ($shown as $filter) {
            $rows .= \sprintf(
                '<label style="display:block;margin-bottom:6px"><input type="number" min="0" step="1" style="width:6em" name="%1$s[%2$d]" value="%3$s"> %4$s</label>',
                esc_attr(self::FIELD),
                $filter->id,
                isset($places[$filter->id]) ? (string) $places[$filter->id] : '',
                esc_html($filter->label),
            );
        }
        printf(
            '<tr class="form-field"><th scope="row">%1$s</th><td>%2$s<p class="description">%3$s</p></td></tr>',
            esc_html__('Filters here', 'orillacart'),
            $rows,
            esc_html__('The order of the filters among this category\'s products, lowest first. Empty ones follow in the shop\'s order. With none numbered, the category takes the order of the category above it, or the shop\'s.', 'orillacart'),
        );
    }

    /**
     * Saves the field from the category's edit screen, whose form WordPress checked (nonce and capability)
     * before it changed the term. Terms changed elsewhere carry no field and keep their order.
     */
    public function save(int $termId): void
    {
        // WordPress checked the nonce of the category form before calling this.
        $given = $_POST[self::FIELD] ?? null;
        if (!\is_array($given) || !current_user_can('edit_term', $termId)) {
            return;
        }
        $places = [];
        foreach ($given as $filterId => $place) {
            if (is_numeric($filterId) && (int) $filterId > 0 && \is_string($place) && ctype_digit(trim($place))) {
                $places[(int) $filterId] = (int) trim($place);
            }
        }
        $this->filters->order($termId, $places);
    }
}
