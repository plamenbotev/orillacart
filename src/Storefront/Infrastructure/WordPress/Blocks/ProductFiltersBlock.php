<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress\Blocks;

use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductCategoryTaxonomy;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductTagTaxonomy;
use OrillaCart\Shared\View\TemplateRenderer;
use OrillaCart\Shared\WordPress\BlockContext;
use OrillaCart\Shared\WordPress\BlockDefinition;
use OrillaCart\Storefront\Infrastructure\WordPress\ShopLinks;
use OrillaCart\Storefront\Infrastructure\WordPress\ShopListing;

/**
 * The shop's filters on their own, for a sidebar or a column beside a Products block (with its own filters
 * off): brands, the shop's filters, price and stock, with how many products each choice leaves. It reads and
 * writes the same address as the Products block, and counts within what that block always shows (its category,
 * featured or sale products), so the numbers match the grid; on a page without one, it sends the choices to the
 * shop page and counts the whole shop. On a category's or tag's own page it counts that one's products, and
 * shows the filters of that category.
 */
final readonly class ProductFiltersBlock implements BlockDefinition
{
    public function __construct(
        private ShopListing $listing,
        private ShopLinks $links,
        private TemplateRenderer $templates,
    ) {}

    public static function name(): string
    {
        return 'orillacart/product-filters';
    }

    public function render(array $attributes, BlockContext $context): string
    {
        // A category's or tag's own page lists its products, as its Products block does.
        $term = is_tax([ProductCategoryTaxonomy::NAME, ProductTagTaxonomy::NAME]) ? get_queried_object() : null;
        $products = $term instanceof \WP_Term ? null : self::productsBlock();
        // The products on this page, or the shop page's.
        $action = match (true) {
            $term instanceof \WP_Term => get_term_link($term),
            $products !== null => get_permalink(),
            default => $this->links->pages()['shop'],
        };
        if (!\is_string($action) || $action === '') {
            return '';
        }
        $fixedCategory = \is_string($products['category'] ?? null) ? sanitize_title($products['category']) : '';
        $fixedTag = \is_string($products['tag'] ?? null) ? sanitize_title($products['tag']) : '';
        if ($term instanceof \WP_Term && $term->taxonomy === ProductCategoryTaxonomy::NAME) {
            $fixedCategory = $term->slug;
        } elseif ($term instanceof \WP_Term) {
            $fixedTag = $term->slug;
        }
        $choices = $this->listing->choices($fixedCategory, 'order', $fixedTag);
        // Counts only, within what the Products block always shows: it lists the products.
        $result = $this->listing->search($choices, 0, 0, true, ($products['featured'] ?? false) === true, ($products['onSale'] ?? false) === true);

        return $this->templates->render('storefront/product-filters.html.twig', [
            'action' => $action,
            // A category or tag the Products block always shows is not the address's to keep.
            'hidden' => $this->listing->hidden($choices, false, $fixedCategory === '' && $fixedTag === ''),
            'panel' => $this->listing->panel($choices, $result),
        ]);
    }

    /**
     * The attributes of the first Products block on the page being shown, also inside columns and groups; null
     * when it has none.
     *
     * @return array<mixed>|null
     */
    private static function productsBlock(): ?array
    {
        $post = get_post();
        if (!$post instanceof \WP_Post || !has_block('orillacart/products', $post)) {
            return null;
        }
        $find = static function (array $blocks) use (&$find): ?array {
            foreach ($blocks as $block) {
                if (!\is_array($block)) {
                    continue;
                }
                if (($block['blockName'] ?? null) === 'orillacart/products') {
                    return \is_array($block['attrs'] ?? null) ? $block['attrs'] : [];
                }
                $inner = \is_array($block['innerBlocks'] ?? null) ? $find($block['innerBlocks']) : null;
                if ($inner !== null) {
                    return $inner;
                }
            }

            return null;
        };

        return $find(parse_blocks($post->post_content));
    }
}
