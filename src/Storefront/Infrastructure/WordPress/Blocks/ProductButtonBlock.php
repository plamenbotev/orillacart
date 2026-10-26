<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress\Blocks;

use OrillaCart\Shared\View\TemplateRenderer;
use OrillaCart\Shared\WordPress\BlockContext;
use OrillaCart\Shared\WordPress\BlockDefinition;
use OrillaCart\Storefront\Infrastructure\WordPress\ProductForBlock;
use OrillaCart\Storefront\Infrastructure\WordPress\ProductParts;

/**
 * What the shop's cards offer to buy the product: add a simple product to the cart at once, else choose its
 * options or see its details on its page.
 */
final readonly class ProductButtonBlock implements BlockDefinition
{
    public function __construct(
        private ProductParts $parts,
        private TemplateRenderer $templates,
    ) {}

    public static function name(): string
    {
        return 'orillacart/product-button';
    }

    public function render(array $attributes, BlockContext $context): string
    {
        $id = ProductForBlock::id($attributes, $context);
        $card = $id === null ? null : $this->parts->card($id);

        return $card === null ? '' : $this->templates->render('storefront/_product_buy.html.twig', [
            'product' => $card,
            'cart_url' => $this->parts->cartUrl(),
        ]);
    }
}
