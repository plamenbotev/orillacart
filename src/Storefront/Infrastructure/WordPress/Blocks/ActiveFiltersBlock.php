<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress\Blocks;

use OrillaCart\Shared\View\TemplateRenderer;
use OrillaCart\Shared\WordPress\BlockContext;
use OrillaCart\Shared\WordPress\BlockDefinition;
use OrillaCart\Storefront\Infrastructure\WordPress\ActiveFilters;
use OrillaCart\Storefront\Infrastructure\WordPress\ProductsOnPage;
use OrillaCart\Storefront\Infrastructure\WordPress\ShopListing;

/**
 * The choices narrowing the page's Products block, placed apart from it: the words searched, a category or tag
 * the shopper chose, brands, the shop's filters' values, price and stock, each a link that drops it, and one
 * that drops them all. The values' labels are the filter panel's, in the shopper's language; the counts behind
 * them are the page's one search's. Nothing on a page without a Products block.
 */
final readonly class ActiveFiltersBlock implements BlockDefinition
{
    public function __construct(
        private ShopListing $listing,
        private ProductsOnPage $page,
        private TemplateRenderer $templates,
    ) {}

    public static function name(): string
    {
        return 'orillacart/active-filters';
    }

    public function render(array $attributes, BlockContext $context): string
    {
        $scope = $this->page->scope();
        if ($scope === null) {
            return '';
        }
        $choices = $scope->choices($this->listing);
        $panel = $this->listing->panel($choices, $scope->search($this->listing, $choices, facets: true));
        $url = ShopListing::pageUrl(1);
        // A category or tag the page fixes is where the shopper is, not a choice to drop.
        $names = [
            'category' => $scope->category === '' ? self::termName($choices['category_id']) : '',
            'tag' => $scope->tag === '' ? self::termName($choices['tag_id']) : '',
        ];

        return $this->templates->render('storefront/active-filters.html.twig', [
            'chips' => ActiveFilters::chips($choices, $panel, $url, $names, $scope->brand),
            'clear_url' => ActiveFilters::clearUrl($url, $scope->category === '' && $scope->tag === ''),
        ]);
    }

    /** The term's name, in the language it is in: the shopper's, as the address names it. */
    private static function termName(?int $id): string
    {
        $term = $id === null || $id === 0 ? null : get_term($id);

        return $term instanceof \WP_Term ? $term->name : '';
    }
}
