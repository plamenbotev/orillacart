<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress\Blocks;

use OrillaCart\Shared\WordPress\BlockContext;
use OrillaCart\Shared\WordPress\BlockDefinition;
use OrillaCart\Storefront\Infrastructure\WordPress\ProductForBlock;

/**
 * One product of the Products block as the shop designs it: the blocks inside (Product image, Post title,
 * Product price, Product button, any other), rendered with the product as theirs. The Products block renders it
 * once per product; without one in it, the shop's own card shows.
 */
final readonly class ProductCardBlock implements BlockDefinition
{
    public const string NAME = 'orillacart/product-card';

    public static function name(): string
    {
        return self::NAME;
    }

    public function render(array $attributes, BlockContext $context): string
    {
        return ProductForBlock::id($attributes, $context) === null ? '' : $context->content;
    }
}
