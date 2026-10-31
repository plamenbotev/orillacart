<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress\Blocks;

use OrillaCart\Shared\WordPress\BlockContext;
use OrillaCart\Shared\WordPress\BlockDefinition;
use OrillaCart\Storefront\Infrastructure\WordPress\ProductForBlock;
use OrillaCart\Storefront\Infrastructure\WordPress\ProductParts;

/**
 * The product's short description in the shopper's language, and the chosen variation's text below it.
 */
final readonly class ProductExcerptBlock implements BlockDefinition
{
    public function __construct(private ProductParts $parts) {}

    public static function name(): string
    {
        return 'orillacart/product-excerpt';
    }

    public function render(array $attributes, BlockContext $context): string
    {
        $id = ProductForBlock::id($attributes, $context);

        return $id === null ? '' : $this->parts->excerpt($id);
    }
}
