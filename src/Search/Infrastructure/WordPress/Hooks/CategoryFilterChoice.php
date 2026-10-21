<?php

declare(strict_types=1);

namespace OrillaCart\Search\Infrastructure\WordPress\Hooks;

use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductCategoryTaxonomy;
use OrillaCart\Search\Application\Dto\SearchFilterView;
use OrillaCart\Search\Application\SearchFilters;
use OrillaCart\Search\Application\ShopSearch;
use OrillaCart\Search\Domain\SearchRuleViolation;
use OrillaCart\Shared\WordPress\AsHook;

/**
 * The "Filters here" field of a product category: the same filters as the category above it (the shop's for a
 * top category), or only those ticked, in their order. A deleted category's choice goes with it.
 */
#[AsHook(ProductCategoryTaxonomy::NAME . '_edit_form_fields', method: 'editField')]
#[AsHook('edited_' . ProductCategoryTaxonomy::NAME, method: 'save')]
#[AsHook('delete_' . ProductCategoryTaxonomy::NAME, method: 'deleted')]
final readonly class CategoryFilterChoice
{
    private const string MODE = 'orillacart_filters_mode';
    private const string CHOSEN = 'orillacart_filters';
    private const string ORDER = 'orillacart_filter_order';

    public function __construct(
        private ShopSearch $search,
        private SearchFilters $filters,
    ) {}

    public function editField(\WP_Term $term): void
    {
        $all = $this->filters->filters();
        if ($all === []) {
            printf(
                '<tr class="form-field"><th scope="row">%s</th><td><p class="description">%s <a href="%s">%s</a></p></td></tr>',
                esc_html__('Filters here', 'orillacart'),
                esc_html__('The shop has no filters yet.', 'orillacart'),
                esc_url(admin_url('admin.php?page=orillacart-settings')),
                esc_html__('Add them in Shop → Settings.', 'orillacart'),
            );

            return;
        }
        $chosen = $this->filters->chosenIn($term->term_id);
        $parent = $term->parent > 0 ? get_term($term->parent, ProductCategoryTaxonomy::NAME) : null;
        $above = $parent instanceof \WP_Term ? self::above($parent->term_id) : [];
        $inherited = array_map(static fn(SearchFilterView $filter): string => $filter->label, $this->search->filters($parent instanceof \WP_Term ? $parent->term_id : null, $above));
        $from = $parent instanceof \WP_Term
            /* translators: %s: the parent category's name */
            ? \sprintf(__('The same as %s', 'orillacart'), $parent->name)
            : __('The shop\'s filters', 'orillacart');

        // Those chosen first, in their order; the others after, in the shop's.
        $places = $chosen === null ? [] : array_flip($chosen);
        usort($all, static fn(SearchFilterView $a, SearchFilterView $b): int => [$places[$a->id] ?? \PHP_INT_MAX, $a->position] <=> [$places[$b->id] ?? \PHP_INT_MAX, $b->position]);
        $rows = '';
        foreach ($all as $place => $filter) {
            $rows .= \sprintf(
                '<tr><td><label><input type="checkbox" name="%1$s[]" value="%2$d"%3$s data-orillacart-own> %4$s</label></td><td><input type="number" min="0" step="1" style="width:5em" name="%5$s[%2$d]" value="%6$d" aria-label="%7$s" data-orillacart-own></td></tr>',
                esc_attr(self::CHOSEN),
                $filter->id,
                isset($places[$filter->id]) ? ' checked' : '',
                esc_html($filter->label),
                esc_attr(self::ORDER),
                $place + 1,
                /* translators: %s: filter label */
                esc_attr(\sprintf(__('Order of %s', 'orillacart'), $filter->label)),
            );
        }
        printf(
            '<tr class="form-field"><th scope="row">%1$s</th><td><fieldset>'
            . '<p><label><input type="radio" name="%2$s" value="inherit"%3$s> %4$s</label> <span class="description">(%5$s)</span></p>'
            . '<p><label><input type="radio" name="%2$s" value="own"%6$s> %7$s</label></p>'
            . '<table class="widefat striped" style="width:auto;min-width:20em;margin-top:8px"><thead><tr><th>%8$s</th><th>%9$s</th></tr></thead><tbody>%10$s</tbody></table>'
            . '<p class="description">%11$s</p></fieldset>'
            // Ticking or numbering a filter chooses the category's own.
            . '<script>document.querySelectorAll("[data-orillacart-own]").forEach((field) => field.addEventListener("input", () => { field.form.querySelector(\'[name="%2$s"][value="own"]\').checked = true; }));</script>'
            . '</td></tr>',
            esc_html__('Filters here', 'orillacart'),
            esc_attr(self::MODE),
            $chosen === null ? ' checked' : '',
            esc_html($from),
            esc_html($inherited === [] ? __('none', 'orillacart') : implode(', ', $inherited)),
            $chosen === null ? '' : ' checked',
            esc_html__('Only the filters ticked below, in their order', 'orillacart'),
            esc_html__('Filter', 'orillacart'),
            esc_html__('Order', 'orillacart'),
            $rows,
            esc_html__('Its subcategories offer the same, unless they choose their own. A filter shows only where the products in view have values for it.', 'orillacart'),
        );
    }

    /**
     * Saves the field from the category's edit screen, whose form WordPress checked (nonce and capability)
     * before it changed the term. Terms changed elsewhere carry no field and keep their filters.
     */
    public function save(int $termId): void
    {
        // WordPress checked the nonce of the category form before calling this.
        $mode = $_POST[self::MODE] ?? null;
        if (!\in_array($mode, ['inherit', 'own'], true) || !current_user_can('edit_term', $termId)) {
            return;
        }
        if ($mode === 'inherit') {
            $this->filters->choose($termId, null);

            return;
        }
        $ticked = \is_array($_POST[self::CHOSEN] ?? null) ? $_POST[self::CHOSEN] : [];
        $order = \is_array($_POST[self::ORDER] ?? null) ? $_POST[self::ORDER] : [];
        $chosen = [];
        foreach ($ticked as $id) {
            if (is_numeric($id) && (int) $id > 0) {
                $place = $order[(int) $id] ?? null;
                $chosen[(int) $id] = is_numeric($place) ? (int) $place : \PHP_INT_MAX;
            }
        }
        asort($chosen);
        try {
            $this->filters->choose($termId, array_keys($chosen));
        } catch (SearchRuleViolation) {
            // More than a category may offer: the choice it had stays.
        }
    }

    public function deleted(int $termId): void
    {
        $this->filters->choose($termId, null);
    }

    /** @return list<int> the categories above it, nearest first */
    private static function above(int $categoryId): array
    {
        return array_values(array_map(intval(...), get_ancestors($categoryId, ProductCategoryTaxonomy::NAME, 'taxonomy')));
    }
}
