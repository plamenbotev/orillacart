<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Domain;

/**
 * The WordPress pages the storefront lives on. A page the shop has none for is not offered: without a cart
 * page, for example, products show no cart link.
 */
final readonly class ShopPages
{
    public function __construct(
        public ?int $shop = null,
        public ?int $cart = null,
        public ?int $checkout = null,
        public ?int $account = null,
    ) {
        foreach ([$shop, $cart, $checkout, $account] as $page) {
            if ($page !== null && $page <= 0) {
                throw new \InvalidArgumentException('A page id is positive.');
            }
        }
        $chosen = array_filter([$shop, $cart, $checkout, $account], static fn(?int $page): bool => $page !== null);
        if (\count($chosen) !== \count(array_unique($chosen))) {
            throw new StorefrontRuleViolation('Choose a different page for each part of the shop.', 'orillacart_page_used_twice');
        }
    }
}
