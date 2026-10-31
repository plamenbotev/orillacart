<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress\Blocks;

use OrillaCart\Shared\WordPress\BlockContext;
use OrillaCart\Shared\WordPress\BlockDefinition;
use OrillaCart\Storefront\Infrastructure\WordPress\ProductForBlock;
use OrillaCart\Storefront\Infrastructure\WordPress\ProductParts;

/**
 * The names of the files the buyer of the product gets; nothing for a product without files.
 */
final readonly class ProductDownloadsBlock implements BlockDefinition
{
    public function __construct(private ProductParts $parts) {}

    public static function name(): string
    {
        return 'orillacart/product-downloads';
    }

    public function render(array $attributes, BlockContext $context): string
    {
        $id = ProductForBlock::id($attributes, $context);

        return $id === null ? '' : $this->parts->downloads($id);
    }
}
