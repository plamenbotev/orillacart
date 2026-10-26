<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress\Blocks;

use OrillaCart\Shared\WordPress\BlockContext;
use OrillaCart\Shared\WordPress\BlockDefinition;
use OrillaCart\Storefront\Infrastructure\WordPress\ProductForBlock;
use OrillaCart\Storefront\Infrastructure\WordPress\ProductParts;

/**
 * The way to buy the product: what is in the box, the chosen variation's text, SKU and stock, the back-in-stock
 * form, its choices, personalisation, quantity and button, the wishlist and the files it brings, with the
 * add-ons' slots. A product page whose template holds it shows the product's parts as blocks: the description
 * then comes alone (ProductPanel).
 */
final readonly class AddToCartBlock implements BlockDefinition
{
    public function __construct(private ProductParts $parts) {}

    public static function name(): string
    {
        return ProductParts::FORM_BLOCK;
    }

    public function render(array $attributes, BlockContext $context): string
    {
        $id = ProductForBlock::id($attributes, $context);

        return $id === null ? '' : $this->parts->form($id, [
            'contents' => ($attributes['showContents'] ?? true) !== false,
            'sku' => ($attributes['showSku'] ?? true) !== false,
            'stock' => ($attributes['showStock'] ?? true) !== false,
            'wishlist' => ($attributes['showWishlist'] ?? true) !== false,
            'downloads' => ($attributes['showDownloads'] ?? true) !== false,
        ]);
    }
}
