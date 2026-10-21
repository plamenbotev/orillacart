<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Application;

/**
 * Products as the shop's cards, for the lists the browser keeps (a visitor's wishlist, the products viewed).
 */
interface ProductCardsHtml
{
    public const int MOST = 24;

    /**
     * @param list<int> $productIds
     *
     * @return array{html: string, ids: list<int>} the cards of those published, in order, and their ids
     */
    public function render(array $productIds): array;
}
