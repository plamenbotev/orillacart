<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress\Blocks;

use OrillaCart\Catalog\Infrastructure\WordPress\ProductBrands;
use OrillaCart\Catalog\Infrastructure\WordPress\ProductCategories;
use OrillaCart\Shared\View\TemplateRenderer;
use OrillaCart\Shared\WordPress\BlockContext;
use OrillaCart\Shared\WordPress\BlockDefinition;

/**
 * The shop's brands, each linking to its page: as tiles with their logos, or as a list of names; all of them by
 * name, or those chosen in the order chosen, in the language shown. Nothing while the shop has none to show.
 */
final readonly class ProductBrandsBlock implements BlockDefinition
{
    public function __construct(
        private ProductBrands $brands,
        private TemplateRenderer $templates,
    ) {}

    public static function name(): string
    {
        return 'orillacart/product-brands';
    }

    public function render(array $attributes, BlockContext $context): string
    {
        $empty = ($attributes['showEmpty'] ?? false) === true;
        $chosen = array_values(array_filter(\is_array($attributes['include'] ?? null) ? $attributes['include'] : [], \is_int(...)));
        $brands = ($attributes['source'] ?? 'all') === 'chosen' ? $this->brands->chosen($chosen, $empty) : $this->brands->all($empty);
        if ($brands === []) {
            return '';
        }
        $layout = ($attributes['layout'] ?? 'grid') === 'list' ? 'list' : 'grid';
        // Without a logo among them, the tiles are their names alone rather than empty frames.
        $showImage = ($attributes['showImage'] ?? true) !== false
            && array_filter($brands, static fn(array $brand): bool => $brand['image_id'] > 0) !== [];

        return $this->templates->render('storefront/product-brands.html.twig', [
            'brands' => array_map(static fn(array $brand): array => [
                'name' => $brand['name'],
                'url' => $brand['url'],
                'count' => $brand['count'],
                'image' => $showImage ? ProductCategories::image($brand['image_id'], $layout === 'list' ? 'thumbnail' : 'medium', 'orillacart-brand__image') : '',
            ], $brands),
            'layout' => $layout,
            'columns' => max(1, min(6, \is_int($attributes['columns'] ?? null) ? $attributes['columns'] : 4)),
            'show_image' => $showImage,
            'show_count' => ($attributes['showCount'] ?? false) === true,
        ]);
    }
}
