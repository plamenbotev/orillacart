<?php

declare(strict_types=1);

namespace OrillaCart\Cart\Application\Dto;

/**
 * What a cart holds, without prices: what checkout reads to price and place an order.
 */
final readonly class CartContents
{
    /**
     * @param list<array{line: int, product_id: int, option_ids: list<int>, bundle_choices: list<int>, quantity: int}> $lines
     * @param list<string> $coupons  the codes entered, checked or not
     * @param list<string> $refusals why add-ons' cart rules refuse the cart, for the customer; it cannot be ordered
     * @param list<string> $giftCards the gift card codes entered, checked or not
     */
    public function __construct(
        public int $cartId,
        public array $lines,
        public array $coupons = [],
        public array $refusals = [],
        public array $giftCards = [],
        /** The customer pays with their store credit, as far as it goes. */
        public bool $useStoreCredit = false,
    ) {}
}
