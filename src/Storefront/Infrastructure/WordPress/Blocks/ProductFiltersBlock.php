<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress\Blocks;

use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductBrandTaxonomy;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductCategoryTaxonomy;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductTagTaxonomy;
use OrillaCart\Shared\View\TemplateRenderer;
use OrillaCart\Shared\WordPress\BlockContext;
use OrillaCart\Shared\WordPress\BlockDefinition;
use OrillaCart\Storefront\Infrastructure\WordPress\ListingScope;
use OrillaCart\Storefront\Infrastructure\WordPress\ProductsOnPage;
use OrillaCart\Storefront\Infrastructure\WordPress\ShopLinks;
use OrillaCart\Storefront\Infrastructure\WordPress\ShopListing;

/**
 * The shop's filters on their own, for a sidebar or a column beside a Products block (with its own filters
 * off): brands, the shop's filters, price and stock, with how many products each choice leaves. It reads and
 * writes the same address as the Products block, and counts within what that block always shows (its category,
 * featured or sale products), so the numbers match the grid; on a page without one, it sends the choices to the
 * shop page and counts the whole shop. On a category's, tag's or brand's own page it counts that one's products,
 * and shows the filters of that category. ProductsOnPage finds the block.
 */
final readonly class ProductFiltersBlock implements BlockDefinition
{
    public function __construct(
        private ShopListing $listing,
        private ShopLinks $links,
        private TemplateRenderer $templates,
        private ProductsOnPage $page,
    ) {}

    public static function name(): string
    {
        return 'orillacart/product-filters';
    }

    public function render(array $attributes, BlockContext $context): string
    {
        $scope = $this->page->scope();
        // A category's, tag's or brand's own page lists its products, as its Products block does.
        $term = is_tax([ProductCategoryTaxonomy::NAME, ProductTagTaxonomy::NAME, ProductBrandTaxonomy::NAME]) ? get_queried_object() : null;
        // The products on this page, or the shop page's.
        $action = match (true) {
            $term instanceof \WP_Term => get_term_link($term),
            $scope !== null => get_permalink(),
            default => $this->links->pages()['shop'],
        };
        if (!\is_string($action) || $action === '') {
            return '';
        }
        $scope ??= new ListingScope();
        $choices = $scope->choices($this->listing);
        // Counts only, within what the Products block always shows: it lists the products.
        $result = $scope->search($this->listing, $choices, facets: true);

        return $this->templates->render('storefront/product-filters.html.twig', [
            'action' => $action,
            // A category or tag the Products block always shows is not the address's to keep.
            'hidden' => $this->listing->hidden($choices, false, $scope->category === '' && $scope->tag === ''),
            'panel' => $this->listing->panel($choices, $result),
        ]);
    }
}
