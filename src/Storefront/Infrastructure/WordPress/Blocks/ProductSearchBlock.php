<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress\Blocks;

use OrillaCart\Shared\View\TemplateRenderer;
use OrillaCart\Shared\WordPress\BlockDefinition;
use OrillaCart\Storefront\Infrastructure\WordPress\ShopLinks;

/**
 * A product search box for a header or a sidebar: it sends the words to the shop page, which lists what
 * matches. Nothing shows without a shop page.
 */
final readonly class ProductSearchBlock implements BlockDefinition
{
    public function __construct(
        private ShopLinks $links,
        private TemplateRenderer $templates,
    ) {}

    public static function name(): string
    {
        return 'orillacart/product-search';
    }

    public static function metadata(): array
    {
        return [
            'title' => __('Product search', 'orillacart'),
            'description' => __('A search box that finds products in the shop.', 'orillacart'),
            'icon' => 'search',
            'keywords' => [__('search', 'orillacart'), __('find', 'orillacart')],
            'attributes' => [
                'placeholder' => ['type' => 'string', 'default' => ''],
            ],
        ];
    }

    public function render(array $attributes): string
    {
        $shop = $this->links->pages()['shop'];
        if ($shop === null) {
            return '';
        }
        $words = $_GET['q'] ?? '';

        return $this->templates->render('storefront/product-search.html.twig', [
            'action' => $shop,
            'search' => \is_string($words) ? sanitize_text_field(wp_unslash($words)) : '',
            'placeholder' => \is_string($attributes['placeholder'] ?? null) && $attributes['placeholder'] !== '' ? $attributes['placeholder'] : __('Search products', 'orillacart'),
        ]);
    }
}
