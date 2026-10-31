<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress\Blocks;

use OrillaCart\Shared\View\TemplateRenderer;
use OrillaCart\Shared\WordPress\BlockContext;
use OrillaCart\Shared\WordPress\BlockDefinition;
use OrillaCart\Storefront\Infrastructure\WordPress\ProductsOnPage;
use OrillaCart\Storefront\Infrastructure\WordPress\ShopListing;

/**
 * How many products the page's Products block lists, placed apart from it: "Showing 13–24 of 57". The total
 * is the one the page's search counts once (ShopListing), so the block adds no query. Nothing on a page without
 * a Products block.
 */
final readonly class ProductsCountBlock implements BlockDefinition
{
    public function __construct(
        private ShopListing $listing,
        private ProductsOnPage $page,
        private TemplateRenderer $templates,
    ) {}

    public static function name(): string
    {
        return 'orillacart/products-count';
    }

    public function render(array $attributes, BlockContext $context): string
    {
        $scope = $this->page->scope();
        if ($scope === null) {
            return '';
        }
        $choices = $scope->choices($this->listing);
        $total = $scope->search($this->listing, $choices)->total;
        $first = ($choices['page'] - 1) * $scope->perPage + 1;

        return $this->templates->render('storefront/products-count.html.twig', [
            // A page past the last shows none.
            'first' => $first > $total ? 0 : $first,
            'last' => min($total, $choices['page'] * $scope->perPage),
            'total' => $total,
        ]);
    }
}
