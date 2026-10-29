<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Infrastructure\WordPress\Hooks;

use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductPostType;
use OrillaCart\Shared\WordPress\AsHook;

/**
 * The product's edit screen in either editor: the product's data in a wide box below the post (the block editor
 * shows boxes in its panel under the content, which can be made taller; the classic screen under the editor),
 * and on the classic screen the gallery, linked products and identifiers in boxes beside it, which the block
 * editor has in its sidebar instead. The product editor script renders into them.
 */
#[AsHook('add_meta_boxes_' . ProductPostType::NAME, method: 'add')]
#[AsHook('is_protected_meta', method: 'protect', filter: true, arguments: 3)]
final readonly class ProductDataBoxes
{
    /** The meta the boxes edit, which the classic screen's Custom Fields box would otherwise list and send back. */
    private const array META = [ProductGallery::META, LinkedProducts::UPSELLS, LinkedProducts::CROSS_SELLS, ProductIdentifiers::GTIN, ProductIdentifiers::MPN];

    public function add(): void
    {
        add_meta_box('orillacart-product-data', __('Product data', 'orillacart'), self::root('orillacart-product-data-root'), ProductPostType::NAME, 'normal', 'high');
        // Only the classic screen: the block editor has these in its sidebar.
        foreach ([
            'orillacart-gallery' => __('Gallery', 'orillacart'),
            'orillacart-linked-products' => __('Linked products', 'orillacart'),
            'orillacart-identifiers' => __('Barcode and part number', 'orillacart'),
        ] as $id => $title) {
            add_meta_box($id, $title, self::root($id . '-root'), ProductPostType::NAME, 'side', 'default', ['__back_compat_meta_box' => true]);
        }
    }

    public function protect(mixed $protected, mixed $key, mixed $type = null): mixed
    {
        return $type === 'post' && \in_array($key, self::META, true) ? true : $protected;
    }

    private static function root(string $id): \Closure
    {
        return static function () use ($id): void {
            printf('<div id="%s" class="orillacart-editor-box"><p>%s</p></div>', esc_attr($id), esc_html__('Loading…', 'orillacart'));
        };
    }
}
