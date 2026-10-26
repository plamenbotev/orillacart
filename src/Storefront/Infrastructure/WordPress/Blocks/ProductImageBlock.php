<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress\Blocks;

use OrillaCart\Catalog\Application\Dto\ProductOffer;
use OrillaCart\Shared\WordPress\BlockContext;
use OrillaCart\Shared\WordPress\BlockDefinition;
use OrillaCart\Storefront\Infrastructure\WordPress\ProductForBlock;
use OrillaCart\Storefront\Infrastructure\WordPress\ProductParts;

/**
 * The product's picture as the shop's cards show it, linking to its page, with a "Sale" badge while it is on
 * sale: for product cards and landing pages.
 */
final readonly class ProductImageBlock implements BlockDefinition
{
    public function __construct(private ProductParts $parts) {}

    public static function name(): string
    {
        return 'orillacart/product-image';
    }

    public function render(array $attributes, BlockContext $context): string
    {
        $id = ProductForBlock::id($attributes, $context);
        $card = $id === null ? null : $this->parts->card($id);
        if ($card === null || !$card['offer'] instanceof ProductOffer) {
            return '';
        }
        $offer = $card['offer'];
        $image = \is_string($card['image']) && $card['image'] !== '' ? $card['image'] : '<span class="orillacart-product-card__no-image" aria-hidden="true"></span>';
        $badge = ($attributes['saleBadge'] ?? true) !== false && $offer->regularPrice !== null && !$offer->priceHidden
            ? '<span class="orillacart-sale-badge">' . esc_html__('Sale', 'orillacart') . '</span>'
            : '';
        $url = \is_string($card['url']) ? $card['url'] : '';

        return ($attributes['isLink'] ?? true) !== false && $url !== ''
            // The picture's alternative text names the product; the link needs no more.
            ? \sprintf('<a class="orillacart-product-image" href="%s" tabindex="-1">%s%s</a>', esc_url($url), $image, $badge)
            : \sprintf('<span class="orillacart-product-image">%s%s</span>', $image, $badge);
    }
}
