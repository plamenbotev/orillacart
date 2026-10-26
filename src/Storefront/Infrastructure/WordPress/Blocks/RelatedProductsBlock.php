<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress\Blocks;

use OrillaCart\Shared\WordPress\BlockContext;
use OrillaCart\Shared\WordPress\BlockDefinition;
use OrillaCart\Storefront\Infrastructure\WordPress\ProductForBlock;
use OrillaCart\Storefront\Infrastructure\WordPress\ProductParts;

/**
 * A row of products beside this one: those like it (its categories, else its tags), or those the shop offers
 * instead (the upsells chosen on the product).
 */
final readonly class RelatedProductsBlock implements BlockDefinition
{
    public function __construct(private ProductParts $parts) {}

    public static function name(): string
    {
        return 'orillacart/related-products';
    }

    public function render(array $attributes, BlockContext $context): string
    {
        $id = ProductForBlock::id($attributes, $context);
        if ($id === null || $this->parts->offer($id) === null) {
            return '';
        }
        $title = \is_string($attributes['title'] ?? null) && trim($attributes['title']) !== '' ? trim($attributes['title']) : null;

        return $this->parts->row(
            $id,
            ($attributes['kind'] ?? 'related') === 'upsells' ? 'upsells' : 'related',
            $title,
            max(1, min(12, \is_int($attributes['count'] ?? null) ? $attributes['count'] : 4)),
        );
    }
}
