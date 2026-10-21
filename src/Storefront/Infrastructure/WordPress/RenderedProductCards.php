<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress;

use OrillaCart\Catalog\Application\Dto\ProductOffer;
use OrillaCart\Shared\View\TemplateRenderer;
use OrillaCart\Storefront\Application\ProductCardsHtml;

final readonly class RenderedProductCards implements ProductCardsHtml
{
    public function __construct(
        private ProductCards $cards,
        private ShopLinks $links,
        private TemplateRenderer $templates,
    ) {}

    public function render(array $productIds): array
    {
        $cards = $this->cards->cards($productIds);
        $cart = $this->links->pages()['cart'];
        $html = '';
        foreach ($cards as $card) {
            $html .= $this->templates->render('storefront/_product_card.html.twig', ['product' => $card, 'cart_url' => $cart, 'heading' => 'h3']);
        }

        return [
            'html' => $html,
            'ids' => array_values(array_map(static fn(array $card): int => $card['offer'] instanceof ProductOffer ? $card['offer']->id : 0, $cards)),
        ];
    }
}
