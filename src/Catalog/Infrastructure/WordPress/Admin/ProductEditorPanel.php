<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Infrastructure\WordPress\Admin;

use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductPostType;
use OrillaCart\Shared\WordPress\EditorPanel;

/**
 * Price, stock, attributes and variations in the product editor.
 */
final readonly class ProductEditorPanel implements EditorPanel
{
    public function postType(): string
    {
        return ProductPostType::NAME;
    }

    public function script(): string
    {
        return 'product-editor';
    }
}
