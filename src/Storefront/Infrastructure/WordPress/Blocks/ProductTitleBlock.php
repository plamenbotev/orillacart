<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress\Blocks;

use OrillaCart\Shared\WordPress\BlockContext;
use OrillaCart\Shared\WordPress\BlockDefinition;
use OrillaCart\Storefront\Infrastructure\WordPress\ProductForBlock;
use OrillaCart\Storefront\Infrastructure\WordPress\ProductParts;

/**
 * The product's name, as the page's heading, or as a link to its page on pages that are not its own (a landing
 * page's spotlight, a product card).
 */
final readonly class ProductTitleBlock implements BlockDefinition
{
    public function __construct(private ProductParts $parts) {}

    public static function name(): string
    {
        return 'orillacart/product-title';
    }

    public function render(array $attributes, BlockContext $context): string
    {
        $id = ProductForBlock::id($attributes, $context);
        $offer = $id === null ? null : $this->parts->offer($id);
        if ($offer === null) {
            return '';
        }
        $level = max(1, min(6, \is_int($attributes['level'] ?? null) ? $attributes['level'] : 1));
        $name = esc_html($offer->name);

        return \sprintf(
            '<h%1$d class="orillacart-product-title">%2$s</h%1$d>',
            $level,
            ($attributes['isLink'] ?? false) === true ? \sprintf('<a href="%s">%s</a>', esc_url((string) get_permalink($offer->id)), $name) : $name,
        );
    }
}
