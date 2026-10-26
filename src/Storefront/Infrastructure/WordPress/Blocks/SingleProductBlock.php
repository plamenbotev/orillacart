<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress\Blocks;

use OrillaCart\Shared\WordPress\BlockContext;
use OrillaCart\Shared\WordPress\BlockDefinition;
use OrillaCart\Storefront\Infrastructure\WordPress\ProductParts;

/**
 * One product anywhere, such as a landing page's spotlight: the product blocks inside (title, gallery, price, Add
 * to cart) show the product chosen here, which the block gives them as their post (providesContext). Nothing
 * while no product is chosen, or the chosen one is not for show.
 */
final readonly class SingleProductBlock implements BlockDefinition
{
    public function __construct(private ProductParts $parts) {}

    public static function name(): string
    {
        return 'orillacart/product';
    }

    public function render(array $attributes, BlockContext $context): string
    {
        $id = $attributes['productId'] ?? 0;

        return \is_int($id) && $id > 0 && $this->parts->offer($id) !== null ? $context->content : '';
    }
}
