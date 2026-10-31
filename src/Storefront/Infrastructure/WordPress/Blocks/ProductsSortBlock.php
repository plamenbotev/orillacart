<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress\Blocks;

use OrillaCart\Shared\View\TemplateRenderer;
use OrillaCart\Shared\WordPress\BlockContext;
use OrillaCart\Shared\WordPress\BlockDefinition;
use OrillaCart\Storefront\Infrastructure\WordPress\ProductsOnPage;
use OrillaCart\Storefront\Infrastructure\WordPress\ShopListing;

/**
 * The sort choice for the page's Products block, placed apart from it (above a sidebar and the grid, say): a
 * plain form that keeps the shopper's other choices, which the script applies as the choice changes. Nothing on
 * a page without a Products block.
 */
final readonly class ProductsSortBlock implements BlockDefinition
{
    public function __construct(
        private ShopListing $listing,
        private ProductsOnPage $page,
        private TemplateRenderer $templates,
    ) {}

    public static function name(): string
    {
        return 'orillacart/products-sort';
    }

    public function render(array $attributes, BlockContext $context): string
    {
        $scope = $this->page->scope();
        if ($scope === null) {
            return '';
        }
        $choices = $scope->choices($this->listing);
        // The other choices, but not a category, tag or brand the page fixes: its own address holds it.
        $kept = [
            ...$this->listing->hidden($choices, false, $scope->category === '' && $scope->tag === ''),
            ...$this->listing->hidden($choices, true),
        ];
        $hidden = array_values(array_filter($kept, static fn(array $field): bool => $field['name'] !== 'sort' && !($field['name'] === 'brand[]' && $field['value'] === (string) $scope->brand)));

        return $this->templates->render('storefront/products-sort.html.twig', [
            'action' => ShopListing::formUrl(),
            'id' => wp_unique_id('orillacart-products-sort-'),
            'sort' => $choices['sort'],
            'sorts' => ShopListing::sortLabels($choices['search'] !== ''),
            'hidden' => $hidden,
            'show_label' => ($attributes['showLabel'] ?? true) !== false,
        ]);
    }
}
