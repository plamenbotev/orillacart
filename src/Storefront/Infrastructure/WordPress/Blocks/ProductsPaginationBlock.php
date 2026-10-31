<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress\Blocks;

use OrillaCart\Shared\View\TemplateRenderer;
use OrillaCart\Shared\WordPress\BlockContext;
use OrillaCart\Shared\WordPress\BlockDefinition;
use OrillaCart\Storefront\Infrastructure\WordPress\PageWindow;
use OrillaCart\Storefront\Infrastructure\WordPress\ProductsOnPage;
use OrillaCart\Storefront\Infrastructure\WordPress\ShopListing;

/**
 * The links to the pages of the page's Products block, placed apart from it (below the columns, say): the
 * previous and next, the first, the last and those around this one, keeping the shopper's choices. The total is
 * the one the page's search counts once, so the block adds no query. Nothing on a page without a Products block.
 */
final readonly class ProductsPaginationBlock implements BlockDefinition
{
    public function __construct(
        private ShopListing $listing,
        private ProductsOnPage $page,
        private TemplateRenderer $templates,
    ) {}

    public static function name(): string
    {
        return 'orillacart/products-pagination';
    }

    public function render(array $attributes, BlockContext $context): string
    {
        $scope = $this->page->scope();
        if ($scope === null) {
            return '';
        }
        $choices = $scope->choices($this->listing);
        $pages = (int) ceil($scope->search($this->listing, $choices)->total / $scope->perPage);
        $steps = PageWindow::steps($choices['page'], $pages, ShopListing::pageUrl(...));

        return $this->templates->render('storefront/products-pagination.html.twig', [
            'page' => $choices['page'],
            'pages' => $pages,
            'page_links' => PageWindow::links($choices['page'], $pages, ShopListing::pageUrl(...)),
            'previous_url' => $steps['previous'],
            'next_url' => $steps['next'],
        ]);
    }
}
