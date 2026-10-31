<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress\Blocks;

use OrillaCart\Shared\WordPress\BlockContext;
use OrillaCart\Shared\WordPress\BlockDefinition;
use OrillaCart\Storefront\Infrastructure\WordPress\ProductForBlock;
use OrillaCart\Storefront\Infrastructure\WordPress\ProductParts;

/**
 * The product's stock line: how many are in stock, in stock, on backorder or out of stock, which changes to the
 * chosen variation's.
 */
final readonly class ProductStockBlock implements BlockDefinition
{
    public function __construct(private ProductParts $parts) {}

    public static function name(): string
    {
        return 'orillacart/product-stock';
    }

    public function render(array $attributes, BlockContext $context): string
    {
        $id = ProductForBlock::id($attributes, $context);

        return $id === null ? '' : $this->parts->stock($id);
    }
}
