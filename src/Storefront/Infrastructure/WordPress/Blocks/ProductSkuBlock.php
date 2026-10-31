<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress\Blocks;

use OrillaCart\Shared\WordPress\BlockContext;
use OrillaCart\Shared\WordPress\BlockDefinition;
use OrillaCart\Storefront\Infrastructure\WordPress\ProductForBlock;
use OrillaCart\Storefront\Infrastructure\WordPress\ProductParts;

/**
 * The product's SKU, which changes to the chosen variation's; nothing when it has none.
 */
final readonly class ProductSkuBlock implements BlockDefinition
{
    public function __construct(private ProductParts $parts) {}

    public static function name(): string
    {
        return 'orillacart/product-sku';
    }

    public function render(array $attributes, BlockContext $context): string
    {
        $id = ProductForBlock::id($attributes, $context);

        return $id === null ? '' : $this->parts->sku($id);
    }
}
